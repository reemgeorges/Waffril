<?php

namespace App\Services\Basic;

use App\Exceptions\Otp\OtpException;
use App\Http\Requests\Basic\BasicRequest;
use App\Http\Traits\GeneralTrait;
use App\Models\DeviceToken;
use App\Services\Auth\PasswordResetGrantService;
use App\Support\OtpChannelHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

abstract class BaseAuthService
{
    use GeneralTrait;

    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected $model;
    protected string $key;
    /** @var class-string */
    protected $resource;
    protected string $guard;

    abstract protected function setVariables(): void;

    public function __construct()
    {
        $this->setVariables();
    }

    protected function issueToken(Model $model, ?string $deviceToken = null, array $deviceMeta = []): array
    {
        // Phase 2A (2026-10-25): tag the token with its issuing guard (kept
        // alongside the pre-existing '*' so no current caller that expects
        // unrestricted abilities breaks) so future routes can enforce
        // ->middleware('ability:admin') / ('ability:user') in addition to
        // the guard.identity model-type check.
        $accessToken = $model->createToken('access-token', [$this->guard, '*']);
        $token       = $accessToken->plainTextToken;
        $tokenId     = $accessToken->accessToken->id ?? null;

        if ($deviceToken) {
            $this->syncDeviceToken($model, $deviceToken, $deviceMeta);
        }

        return [$token, $tokenId];
    }

    /**
     * Phase 3B (2026-10-25). Shared "final step" of login(): either issue
     * a token immediately, or — if this guard's module has
     * `require_login_challenge` set — issue a login_challenge OTP instead
     * and require verifyLoginChallenge() before any token is handed out.
     * Both UserAuthService and AdminAuthService call this instead of
     * duplicating the token-issuance block.
     */
    protected function completeLogin(Model $actor, Request $request): array
    {
        if ($this->loginChallengeRequired()) {
            OtpChannelHelper::send($actor, 'login_challenge', $actor->otp_delivery_method ?? 'email');

            return [
                'token' => null,
                $this->key => $this->resource::make($actor),
                'need_login_challenge' => true,
            ];
        }

        [$token, $tokenId] = $this->issueToken(
            $actor,
            $request->input('token_device'),
            [
                'platform' => $request->input('platform', 'unknown'),
                'device_name' => $request->input('device_name'),
                'device_id' => $request->input('device_id'),
            ]
        );

        return ['token' => $token, 'token_id' => $tokenId, $this->key => $this->resource::make($actor)];
    }

    /**
     * Confirm a login_challenge OTP and issue the token login() withheld.
     * Not wired to any live route by default — only reachable at all once
     * a module sets `require_login_challenge` (both Admin and User default
     * to false, i.e. no behavior change from before this existed).
     */
    public function verifyLoginChallenge(BasicRequest $request): array
    {
        $this->otpActionAllowed('login_challenge');

        $actor = $this->resolveByEmailOrPhone($request);

        if (! OtpChannelHelper::verify($actor, 'login_challenge', (string) $request->input('otp'))) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        [$token, $tokenId] = $this->issueToken(
            $actor,
            $request->input('token_device'),
            [
                'platform' => $request->input('platform', 'unknown'),
                'device_name' => $request->input('device_name'),
                'device_id' => $request->input('device_id'),
            ]
        );

        return ['token' => $token, 'token_id' => $tokenId, $this->key => $this->resource::make($actor)];
    }

    protected function loginChallengeRequired(): bool
    {
        return (bool) config("auth_modules.modules.{$this->guard}.require_login_challenge", false);
    }

    /**
     * Phase 3B (2026-10-25). Guards every OTP-backed action behind this
     * module's config('auth_modules.modules.{guard}.otp') declaration,
     * instead of every action being unconditionally available (or
     * unconditionally fatal, as every one of them was before Phase 3A).
     * A module can expose e.g. reset_password without register.
     */
    protected function otpActionAllowed(string $action): void
    {
        $module = config("auth_modules.modules.{$this->guard}", []);

        if (! ($module['otp']['enabled'] ?? false) || ! in_array($action, $module['otp']['actions'] ?? [], true)) {
            throw new OtpException(
                "The \"{$action}\" action is not enabled for the \"{$this->guard}\" module.",
                422
            );
        }
    }

    protected function syncDeviceToken(Model $model, string $deviceToken, array $deviceMeta = []): DeviceToken
    {
        $deviceId = $deviceMeta['device_id'] ?? null;

        if ($deviceId) {
            DeviceToken::query()
                ->where('tokenable_type', get_class($model))
                ->where('tokenable_id', $model->id)
                ->where('device_id', $deviceId)
                ->where('token', '!=', $deviceToken)
                ->update([
                    'is_active'   => false,
                    'revoked_at'   => now(),
                    'updated_at'   => now(),
                ]);
        }

        return DeviceToken::updateOrCreate(
            ['token' => $deviceToken],
            [
                'tokenable_type' => get_class($model),
                'tokenable_id'   => $model->id,
                'platform'       => $deviceMeta['platform'] ?? 'unknown',
                'device_name'    => $deviceMeta['device_name'] ?? null,
                'device_id'      => $deviceId,
                'is_active'      => true,
                'last_seen_at'    => now(),
                'revoked_at'     => null,
                'meta'           => $deviceMeta ?: null,
            ]
        );
    }
    public function revokeDeviceToken(Request $request): void
    {
        $token = $request->input('token_device');

        if (! $token) {
            return;
        }

        DeviceToken::query()
            ->where('token', $token)
            ->update([
                'is_active'  => false,
                'revoked_at'  => now(),
                'updated_at'  => now(),
            ]);
    }
    public function syncLoggedInDeviceToken(Request $request): array
    {
        $actor = $request->user($this->guard);

        if (! $actor) {
            throw new HttpResponseException($this->unAuthorizeResponse());
        }

        $this->syncDeviceToken($actor, (string) $request->input('token_device'), [
            'platform'    => $request->input('platform', 'unknown'),
            'device_name' => $request->input('device_name'),
            'device_id'   => $request->input('device_id'),
        ]);

        return ['message' => __('messages.device_token_saved')];
    }

    /* ===== Registration ===== */

    public function register(BasicRequest $request): array
    {
        $this->otpActionAllowed('register');

        $in = $request->validated();
        $channel = $in['otp_delivery_method'] ?? 'email';

        if (! empty($in['phone_number'])) {
            $in['phone_number'] = preg_replace('/[\s-]+/', '', (string) $in['phone_number']);
        }

        $userQuery = $this->model::query();
        $hasLookup = false;

        if (! empty($in['email'])) {
            $userQuery->orWhere('email', $in['email']);
            $hasLookup = true;
        }
        if (! empty($in['prefix_phone']) && ! empty($in['phone_number'])) {
            $userQuery->orWhere(function ($q) use ($in) {
                $q->where('prefix_phone', $in['prefix_phone'])->where('phone_number', $in['phone_number']);
            });
            $hasLookup = true;
        }

        $user = $hasLookup ? $userQuery->first() : null;

        if ($user) {
            return $this->checkExistingAccountStatus($user);
        }

        $in['otp_delivery_method'] = $channel;
        $in['password'] = Hash::make($in['password']);

        /** @var \Illuminate\Database\Eloquent\Model $user */
        $user = $this->model::create($in);

        OtpChannelHelper::send($user, 'register', $channel);

        return [
            'message'     => __('messages.otp_sent'),
            $this->key    => $this->resource::make($user),
            'need_login'  => false,
            'need_verify' => true,
        ];
    }

    /**
     * Shared by register() (Phase 3B — pulled out of UserAuthService's
     * duplicate checkUserStatus() so it works for any module, not just User).
     */
    protected function checkExistingAccountStatus(Model $account): array
    {
        if ($account->status) {
            return [
                'message' => __('messages.need_login'),
                $this->key => $this->resource::make($account),
                'need_login' => true,
                'need_verify' => false,
            ];
        }

        OtpChannelHelper::send($account, 'register', $account->otp_delivery_method ?? 'email');

        return [
            'message' => __('messages.otp_sent'),
            $this->key => $this->resource::make($account),
            'need_login' => false,
            'need_verify' => true,
        ];
    }

    public function resendRegisterOtp(BasicRequest $request): array
    {
        $this->otpActionAllowed('resend_register');

        $user = $this->model::findOrFail($request->input('id'));
        if (!$user->otp_delivery_method) {
            throw new HttpResponseException($this->requiredField(__('messages.channel_not_selected')));
        }
        OtpChannelHelper::send($user, 'register', $user->otp_delivery_method);
        return ['message' => __('messages.otp_resent')];
    }

    abstract public function verifyOtp(BasicRequest $request): array;

    /* ===== Login (User/Admin) ===== */
    abstract public function login(BasicRequest $request): array;

    /* ===== Reset Password (User/Admin) ===== */

    /**
     * Phase 3B (2026-10-25): resolves purely from $this->model (already
     * correct per-guard — AdminAuthService sets it to Admin::class,
     * UserAuthService to User::class) instead of branching on a
     * client-supplied `actor` string that never actually changed which
     * table was queried. Phone lookup is skipped entirely for the admin
     * guard, which has no phone columns.
     */
    protected function resolveByEmailOrPhone(BasicRequest $request): Model
    {
        $target = null;

        if ($request->filled('email')) {
            $target = $this->model::where('email', $request->input('email'))->first();
        } elseif ($this->guard !== 'admin' && $request->filled('prefix_phone') && $request->filled('phone_number')) {
            $target = $this->model::where('prefix_phone', $request->input('prefix_phone'))
                ->where('phone_number', $request->input('phone_number'))
                ->first();
        }

        if (! $target) {
            throw new HttpResponseException($this->requiredField(__('messages.account_not_found')));
        }

        return $target;
    }

    /**
     * Phase 3B fix (found while writing this phase's own tests): the first
     * draft ignored $request->input('channel') entirely and always fell
     * back to the account's stored otp_delivery_method, silently dropping
     * the (pre-existing, intentional) ability for a client to pick which
     * channel a given reset request should use.
     */
    protected function resetChannel(BasicRequest $request, Model $target): string
    {
        if ($this->guard === 'admin') {
            return 'email';
        }

        return $request->string('channel')->toString() ?: ($target->otp_delivery_method ?? 'email');
    }

    /**
     * Same lookup as resolveByEmailOrPhone(), but returns null instead of
     * throwing — used by sendResetOtp() below so a non-existent account
     * doesn't produce a distinguishable response (see that method).
     */
    protected function resolveByEmailOrPhoneOrNull(BasicRequest $request): ?Model
    {
        if ($request->filled('email')) {
            return $this->model::where('email', $request->input('email'))->first();
        }

        if ($this->guard !== 'admin' && $request->filled('prefix_phone') && $request->filled('phone_number')) {
            return $this->model::where('prefix_phone', $request->input('prefix_phone'))
                ->where('phone_number', $request->input('phone_number'))
                ->first();
        }

        return null;
    }

    /**
     * Phase 3C (2026-10-25) — enumeration resistance. Previously this threw
     * a distinct "account not found" (400) for a non-existent email/phone,
     * while an existing account got "otp_sent" (200) — letting any caller
     * test arbitrary emails to discover which ones have accounts. Now
     * returns the identical response either way; a code is only actually
     * sent when the account genuinely exists. A rate-limit/cooldown
     * OtpException (429) still propagates — "you're requesting too fast"
     * leaks nothing about whether the account exists, since it fires
     * identically whether or not this specific call found one (the limiter
     * keys include the destination hash, not just the resolved account).
     */
    public function sendResetOtp(BasicRequest $request): array
    {
        $this->otpActionAllowed('reset_password');

        $target = $this->resolveByEmailOrPhoneOrNull($request);

        if ($target) {
            OtpChannelHelper::send($target, 'reset_password', $this->resetChannel($request, $target));
        }

        return ['message' => __('messages.otp_sent_if_account_exists')];
    }

    /**
     * Phase 3B (2026-10-25): previously fully commented out (dead code) —
     * App\Http\Controllers\FatherAuthController::verifyResetOtp() called
     * this method on a class that never defined it, so the reset flow was
     * already broken at this exact step regardless of anything else.
     *
     * On success, issues a single-use, short-lived, account-bound
     * App\Services\Auth\PasswordResetGrantService grant instead of a
     * broad Sanctum token — see that service's doc comment. The client
     * must present this `reset_token` to resetPassword() below; nothing
     * about completing OTP verification authenticates the caller as this
     * account for any OTHER purpose.
     */
    public function verifyResetOtp(BasicRequest $request): array
    {
        $this->otpActionAllowed('reset_password');

        $target = $this->resolveByEmailOrPhone($request);

        if (! OtpChannelHelper::verify($target, 'reset_password', (string) $request->input('otp'), $this->resetChannel($request, $target))) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        $resetToken = app(PasswordResetGrantService::class)->issue($target);

        return ['message' => __('messages.otp_verified'), 'reset_token' => $resetToken];
    }

    /**
     * Phase 3B (2026-10-25) — CRITICAL SECURITY FIX.
     *
     * Before this phase, this method updated an account's password from
     * nothing but a client-supplied actor+email/phone+new-password, with
     * NO check that OTP verification had ever happened for this account.
     * Any anonymous caller could reset any admin's or user's password
     * outright: POST {actor:"admin", email:"victim@example.com",
     * password:"anything"} and it would just work. It now REQUIRES and
     * atomically consumes a reset_token grant from verifyResetOtp() above.
     */
    public function resetPassword(BasicRequest $request): array
    {
        $this->otpActionAllowed('reset_password');

        $target = $this->resolveByEmailOrPhone($request);
        $resetToken = (string) $request->input('reset_token');

        if ($resetToken === '' || ! app(PasswordResetGrantService::class)->consume($target, $resetToken)) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_or_expired_reset_token')));
        }

        $target->update(['password' => Hash::make($request->input('password'))]);

        return ['message' => __('messages.password_updated')];
    }

    /* ===== Phone change ===== */

    public function requestPhoneChange(Request $request): array
    {
        $this->otpActionAllowed('phone_change');

        $actorUser = $request->user($this->guard);
        if (!$actorUser) throw new HttpResponseException($this->unAuthorizeResponse());

        $channel = $request->string('channel')->toString() ?: 'sms';
        OtpChannelHelper::send($actorUser, 'phone_change', $channel);

        return ['message' => __('messages.otp_sent')];
    }

    public function verifyPhoneChangeOtp(Request $request): array
    {
        $actorUser = $request->user($this->guard);
        if (!$actorUser) throw new HttpResponseException($this->unAuthorizeResponse());

        $otp = (string) $request->input('otp');
        $channel = $actorUser->otp_delivery_method ?? 'email';

        $ok = OtpChannelHelper::verify($actorUser, 'phone_change', $otp, $channel);
        if (!$ok) throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));

        if ($request->filled('prefix_phone') && $request->filled('phone_number')) {
            $prefix_phone = $request->input('prefix_phone');
            $phone_number = preg_replace('/[\s-]+/', '', (string) $request->input('phone_number'));

            if ($this->model::where(function ($query) use ($prefix_phone, $phone_number, $actorUser) {
                $query->where('prefix_phone', $prefix_phone)
                      ->where('phone_number', $phone_number)
                      ->where('id', '!=', $actorUser->id);
            })->exists()) {
                throw new HttpResponseException($this->requiredField(__('messages.invalid_phone_number')));
            }

            $actorUser->update([
                'prefix_phone' => $prefix_phone,
                'phone_number' => $phone_number,
                'phone_status' => true,
                'phone_verified_at' => now(),
            ]);
        }

        return [
            'message' => __('messages.phone_updated'),
            'token' => null,
            $this->key => $this->resource::make($actorUser->fresh()),
        ];
    }

    /* ===== Email change (Phase 3B, new — mirrors phone change) ===== */

    public function requestEmailChange(Request $request): array
    {
        $this->otpActionAllowed('email_change');

        $actorUser = $request->user($this->guard);
        if (!$actorUser) throw new HttpResponseException($this->unAuthorizeResponse());

        OtpChannelHelper::send($actorUser, 'email_change', 'email');

        return ['message' => __('messages.otp_sent')];
    }

    public function verifyEmailChangeOtp(Request $request): array
    {
        $actorUser = $request->user($this->guard);
        if (!$actorUser) throw new HttpResponseException($this->unAuthorizeResponse());

        $otp = (string) $request->input('otp');

        if (! OtpChannelHelper::verify($actorUser, 'email_change', $otp, 'email')) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        if ($request->filled('email')) {
            $newEmail = $request->input('email');

            if ($this->model::where('email', $newEmail)->where('id', '!=', $actorUser->id)->exists()) {
                throw new HttpResponseException($this->requiredField(__('messages.email_already_taken')));
            }

            $actorUser->update([
                'email' => $newEmail,
                'email_status' => true,
                'email_verified_at' => now(),
            ]);
        }

        return [
            'message' => __('messages.email_updated'),
            $this->key => $this->resource::make($actorUser->fresh()),
        ];
    }

    // تحديث اللغة
    public function updateLanguage(Request $request): array
    {
        $actorUser = $request->user($this->guard);
        if (!$actorUser) throw new HttpResponseException($this->unAuthorizeResponse());

        $lang = $request->string('language')->toString() ?: 'ar';
        $actorUser->update(['language' => $lang]);

        return [$this->key => $this->resource::make($actorUser->fresh())];
    }
}
