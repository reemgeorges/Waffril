<?php

namespace App\Services\Functional;

use App\Http\Traits\GeneralTrait;
use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\PasswordResetGrantService;
use App\Support\OtpChannelHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Unified reset password service:
 * - sendResetOtp(actor,user/admin)
 * - verifyResetOtp(actor,user/admin) => reset_token
 * - resetPassword(reset_token)
 *
 * Phase 3C (2026-10-25) — "review unused ResetPasswordService ... safely."
 * This class is NOT routed anywhere (no facade, no controller, no route —
 * confirmed by grep; the real, live reset-password flow is
 * App\Services\Basic\BaseAuthService, wired through
 * App\Http\Controllers\FatherAuthController). It is reviewed and hardened
 * here as defense in depth, in case anything is ever wired to it later,
 * rather than left as a latent, insecure trap:
 *  - verifyResetOtp() previously issued a broad Sanctum personal access
 *    token (default abilities ['*']) as the "proof you verified the OTP"
 *    credential — the exact pattern BaseAuthService's own reset flow was
 *    fixed away from in Phase 3B (a leaked token could do far more than
 *    reset a password). Now issues a single-use,
 *    App\Services\Auth\PasswordResetGrantService grant instead, same as
 *    the real flow.
 *  - resetPassword() previously authenticated via $request->user() (i.e.
 *    required a valid bearer token of ANY kind to be present — not
 *    specifically the reset token this class itself issued, and not
 *    checking is_active/soft-delete status either). Now requires and
 *    atomically consumes a reset_token grant for the specific resolved
 *    account, matching BaseAuthService::resetPassword()'s fixed design.
 *  - sendResetOtp() previously threw a distinct "account not found" error
 *    for a nonexistent account while an existing one got "otp_sent" —
 *    enumeration. Now responds identically either way, same fix as
 *    BaseAuthService::sendResetOtp() (Phase 3C).
 */
class ResetPasswordService
{
    use GeneralTrait;

    /** Resolve target model & allowed channel according to actor, or null if not found. */
    protected function resolveTargetOrNull(Request $request): ?array
    {
        $actor = $request->input('actor'); // 'user' | 'admin'

        if ($actor === 'admin') {
            $admin = Admin::where('email', $request->input('email'))->first();

            return $admin ? [$actor, $admin, 'email'] : null;
        }

        if ($request->filled('email')) {
            $user = User::where('email', $request->input('email'))->first();

            return $user ? [$actor, $user, 'email'] : null;
        }

        $user = User::where('prefix_phone', $request->input('prefix_phone'))
            ->where('phone_number', $request->input('phone_number'))
            ->first();

        return $user ? [$actor, $user, $request->input('channel')] : null;
    }

    /** Send reset OTP (user/admin) — same response whether or not the account exists. */
    public function sendResetOtp(Request $request): array
    {
        $resolved = $this->resolveTargetOrNull($request);

        if ($resolved) {
            [$actor, $model, $channel] = $resolved;

            if (! ($actor === 'admin' && $channel !== 'email')
                && ! (in_array($channel, ['sms', 'whatsapp']) && (empty($model->prefix_phone) || empty($model->phone_number)))
                && ! ($channel === 'email' && empty($model->email))
            ) {
                OtpChannelHelper::send($model, 'reset_password', $channel);
            }
        }

        return ['message' => __('messages.otp_sent_if_account_exists')];
    }

    /** Verify reset OTP => issue a single-use reset_token grant. */
    public function verifyResetOtp(Request $request): array
    {
        $actor = $request->input('actor');

        if ($actor === 'admin') {
            $admin = Admin::where('email', $request->input('email'))->firstOrFail();

            if (! OtpChannelHelper::verify($admin, 'reset_password', $request->input('otp'), 'email')) {
                throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
            }

            return [
                'reset_token' => app(PasswordResetGrantService::class)->issue($admin),
                'actor' => 'admin',
                'admin' => ['id' => $admin->id, 'email' => $admin->email],
            ];
        }

        if ($request->filled('email')) {
            $user = User::where('email', $request->input('email'))->firstOrFail();
            $channel = 'email';
        } else {
            $user = User::where('prefix_phone', $request->input('prefix_phone'))
                ->where('phone_number', $request->input('phone_number'))
                ->firstOrFail();
            $channel = $request->input('channel', $user->otp_delivery_method ?? null);
        }

        if (! OtpChannelHelper::verify($user, 'reset_password', $request->input('otp'), $channel)) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_otp')));
        }

        return [
            'reset_token' => app(PasswordResetGrantService::class)->issue($user),
            'actor' => 'user',
            'user' => ['id' => $user->id, 'email' => $user->email],
        ];
    }

    /** Set new password — requires and consumes the reset_token grant from verifyResetOtp(). */
    public function resetPassword(Request $request): array
    {
        $resolved = $this->resolveTargetOrNull($request);

        if (! $resolved) {
            throw new HttpResponseException($this->requiredField(__('messages.account_not_found')));
        }

        /** @var Model $target */
        [, $target] = $resolved;

        $resetToken = (string) $request->input('reset_token');

        if ($resetToken === '' || ! app(PasswordResetGrantService::class)->consume($target, $resetToken)) {
            throw new HttpResponseException($this->requiredField(__('messages.invalid_or_expired_reset_token')));
        }

        $target->password = Hash::make($request->input('password'));
        $target->save();

        return ['message' => __('messages.password_updated')];
    }
}
