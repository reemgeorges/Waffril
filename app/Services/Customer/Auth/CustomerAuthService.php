<?php

namespace App\Services\Customer\Auth;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Customer\CustomerAccountService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * WAFFRLI customer identity (2026-11-03) — phone OTP only.
 *
 * 1. POST otp/request {phone}     → a code is sent (same answer whether or
 *                                   not the phone has an account).
 * 2. POST otp/verify {phone,code} → existing active customer: token
 *                                   (status `logged_in`); unknown phone:
 *                                   short-lived single-use
 *                                   `registration_token` (status
 *                                   `registration_required`).
 * 3. POST register {registration_token, first_name, last_name, …} → the
 *                                   account is created only here, after
 *                                   the phone was proven.
 *
 * One active device: every successful login revokes ALL previous Sanctum
 * tokens of the customer and deactivates the push tokens of other devices.
 * Suspended customers cannot log in; accounts in their 30-day deletion
 * window can only come back through the dedicated recovery flow.
 */
class CustomerAuthService
{
    public function __construct(protected PhoneOtpService $otp) {}

    public function requestOtp(string $phone, ?string $ip): array
    {
        return $this->otp->issue($phone, PhoneOtpService::PURPOSE_LOGIN, $ip);
    }

    /** @return array{status: string, token?: string, user?: User, registration_token?: string, expires_in?: int} */
    public function verifyOtp(string $phone, string $code, array $device, ?string $ip): array
    {
        if (! $this->otp->verify($phone, PhoneOtpService::PURPOSE_LOGIN, $code, $ip)) {
            throw new BusinessRuleException(__('messages.customer.otp_invalid'), 422);
        }

        $user = User::withTrashed()->where('phone', $phone)->first();
        if (! $user) {
            $ttl = (int) config('waffrli.customer.registration_token_ttl_minutes', 15);

            return [
                'status' => 'registration_required',
                'registration_token' => Crypt::encryptString(json_encode(['phone' => $phone, 'nonce' => (string) Str::uuid(), 'exp' => now()->addMinutes($ttl)->timestamp])),
                'expires_in' => $ttl * 60,
            ];
        }

        $this->assertCanLogIn($user);

        return ['status' => 'logged_in', 'token' => $this->issueToken($user, $device), 'user' => $user->fresh()];
    }

    public function register(string $registrationToken, array $profile, array $device): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($registrationToken), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new BusinessRuleException(__('messages.customer.registration_token_invalid'), 422);
        }
        if (($payload['exp'] ?? 0) < now()->timestamp || ! Cache::add('customer-registration-token:'.($payload['nonce'] ?? ''), 1, now()->addDay())) {
            throw new BusinessRuleException(__('messages.customer.registration_token_invalid'), 422);
        }

        $user = DB::transaction(function () use ($payload, $profile) {
            if (User::withTrashed()->where('phone', $payload['phone'])->lockForUpdate()->exists()) {
                throw new BusinessRuleException(__('messages.customer.phone_already_registered'), 409);
            }

            $first = trim($profile['first_name']);
            $last = trim($profile['last_name']);

            return User::create([
                'name' => "{$first} {$last}",
                'first_name' => $first,
                'last_name' => $last,
                'email' => null,
                'password' => Str::random(64), // never used: customers authenticate by phone OTP only
                'phone' => $payload['phone'],
                'language' => $profile['language'] ?? app()->getLocale(),
                'is_active' => true,
                'otp_channel' => 'sms',
            ])->forceFill([
                'phone_verified_at' => now(),
                'profile_completed_at' => now(),
                'account_status' => User::ACCOUNT_ACTIVE,
            ]);
        });
        $user->save();

        return ['token' => $this->issueToken($user, $device), 'user' => $user->fresh()];
    }

    public function logout(User $user, ?string $deviceId): void
    {
        $user->currentAccessToken()?->delete();
        DeviceToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
            ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
            ->update(['is_active' => false, 'revoked_at' => now()]);
    }

    public function requestRecoveryOtp(string $phone, ?string $ip): array
    {
        $user = User::onlyTrashed()->where('phone', $phone)->first();
        if ($user && $user->anonymized_at === null && $user->recovery_until && $user->recovery_until->isFuture()) {
            return $this->otp->issue($phone, PhoneOtpService::PURPOSE_RECOVERY, $ip);
        }

        // Same answer either way: never reveal whether a phone has a recoverable account.
        return ['channel' => null, 'expires_in' => (int) config('otp.ttl_minutes', 10) * 60, 'resend_in' => (int) config('otp.resend_cooldown_seconds', 60)];
    }

    public function recover(string $phone, string $code, array $device, ?string $ip): array
    {
        if (! $this->otp->verify($phone, PhoneOtpService::PURPOSE_RECOVERY, $code, $ip)) {
            throw new BusinessRuleException(__('messages.customer.otp_invalid'), 422);
        }

        $user = DB::transaction(function () use ($phone) {
            $user = User::onlyTrashed()->where('phone', $phone)->lockForUpdate()->first();
            if (! $user || $user->anonymized_at !== null || ! $user->recovery_until || $user->recovery_until->isPast()) {
                throw new BusinessRuleException(__('messages.customer.recovery_not_available'), 409);
            }
            $user->restore();
            $user->forceFill(['recovery_until' => null])->save();

            return $user;
        });

        if ($user->isSuspended()) {
            throw new BusinessRuleException(__('messages.customer.account_suspended'), 403);
        }

        return ['token' => $this->issueToken($user, $device), 'user' => $user->fresh()];
    }

    /** Starts the 30-day deletion window (orders and money records are kept). */
    public function requestDeletion(User $user): User
    {
        $open = $user->orders()->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->exists();
        if ($open) {
            throw new BusinessRuleException(__('messages.customer.deletion_blocked_open_orders'), 409);
        }

        return app(CustomerAccountService::class)->requestDeletion($user);
    }

    public function assertCanLogIn(User $user): void
    {
        if ($user->trashed()) {
            throw new BusinessRuleException($user->anonymized_at === null && $user->recovery_until?->isFuture()
                ? __('messages.customer.account_pending_deletion', ['until' => $user->recovery_until->toDateString()])
                : __('messages.customer.account_closed'), 409);
        }
        if ($user->isSuspended() || ! $user->is_active) {
            throw new BusinessRuleException(__('messages.customer.account_suspended'), 403);
        }
    }

    /** One active device: revoke every other session and push token. */
    protected function issueToken(User $user, array $device): string
    {
        return DB::transaction(function () use ($user, $device) {
            $user->tokens()->delete();
            DeviceToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
                ->where(fn ($query) => $query->whereNull('device_id')->orWhere('device_id', '!=', (string) ($device['device_id'] ?? '')))
                ->update(['is_active' => false, 'revoked_at' => now()]);

            return $user->createToken(mb_substr((string) ($device['device_name'] ?? 'customer-app'), 0, 100), ['user', '*'])->plainTextToken;
        });
    }
}
