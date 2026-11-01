<?php

namespace App\Services\Customer\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\Otp;
use App\Services\Messaging\TextMessenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * WAFFRLI customer identity (2026-11-03) — OTPs bound to a PHONE NUMBER
 * (no account needed yet: registration starts from a phone).
 *
 * Same guarantees as App\Services\Auth\OtpService, on the same `otps`
 * table (verifiable_id NULL + destination_hash, allowed by the
 * chk_otps_binding constraint):
 *  - CSPRNG codes; only a bcrypt hash is stored; the phone is stored only
 *    as an HMAC (destination_hash);
 *  - expiry (otp.ttl_minutes), resend cooldown, per-code attempt ceiling,
 *    per-phone and per-IP send/verify rate limits;
 *  - single use: row lock + conditional consume;
 *  - purposes are isolated (`customer_login`, `customer_recovery`).
 * The code is never returned by any API.
 */
class PhoneOtpService
{
    public const TYPE = 'customer_phone';

    public const PURPOSE_LOGIN = 'customer_login';

    public const PURPOSE_RECOVERY = 'customer_recovery';

    public function __construct(protected TextMessenger $messenger) {}

    public static function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', $phone, (string) config('app.key'));
    }

    /** @return array{channel: string, expires_in: int, resend_in: int} */
    public function issue(string $phone, string $purpose, ?string $ip): array
    {
        $hash = self::phoneHash($phone);
        $this->guard('send-phone', $hash, $purpose, (int) config('otp.max_sends', 5), (int) config('otp.send_window_minutes', 60));
        if ($ip) {
            $this->guard('send-ip', $ip, $purpose, (int) config('otp.max_sends', 5) * 4, (int) config('otp.send_window_minutes', 60));
        }

        $cooldown = max(0, (int) config('otp.resend_cooldown_seconds', 60));
        $last = Otp::query()->where('verifiable_type', self::TYPE)->where('destination_hash', $hash)->where('purpose', $purpose)->latest('id')->first();
        if ($last && $cooldown > 0 && $last->created_at->copy()->addSeconds($cooldown)->isFuture()) {
            throw new BusinessRuleException(__('messages.customer.otp_cooldown', ['seconds' => (int) now()->diffInSeconds($last->created_at->copy()->addSeconds($cooldown), true) + 1]), 429);
        }

        $length = max(4, (int) config('otp.code_length', 6));
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $ttl = max(1, (int) config('otp.ttl_minutes', 10));

        $otp = DB::transaction(function () use ($hash, $purpose, $code, $ttl, $ip) {
            Otp::query()->where('verifiable_type', self::TYPE)->where('destination_hash', $hash)->where('purpose', $purpose)
                ->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            return Otp::create([
                'verifiable_type' => self::TYPE, 'verifiable_id' => null, 'purpose' => $purpose,
                'channel' => config('waffrli.customer.otp_channel', 'sms'), 'destination_hash' => $hash,
                'code_hash' => Hash::make($code), 'attempts' => 0, 'max_attempts' => max(1, (int) config('otp.max_attempts', 5)),
                'expires_at' => now()->addMinutes($ttl), 'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
            ]);
        });

        RateLimiter::hit($this->key('send-phone', $hash, $purpose), (int) config('otp.send_window_minutes', 60) * 60);
        if ($ip) {
            RateLimiter::hit($this->key('send-ip', $ip, $purpose), (int) config('otp.send_window_minutes', 60) * 60);
        }

        try {
            $channel = $this->messenger->deliver($phone, __('messages.customer.otp_text', ['code' => $code, 'minutes' => $ttl]));
            $otp->forceFill(['channel' => $channel, 'delivered_at' => now()])->save();
        } catch (Throwable $e) {
            $otp->forceFill(['invalidated_at' => now(), 'delivery_failed_at' => now(), 'last_delivery_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw new BusinessRuleException(__('messages.customer.otp_delivery_failed'), 503);
        }

        return ['channel' => $channel, 'expires_in' => $ttl * 60, 'resend_in' => $cooldown];
    }

    public function verify(string $phone, string $purpose, string $code, ?string $ip): bool
    {
        $hash = self::phoneHash($phone);
        $this->guard('verify-phone', $hash, $purpose, (int) config('otp.max_verifies', 10), (int) config('otp.verify_window_minutes', 15), true);
        if ($ip) {
            $this->guard('verify-ip', $ip, $purpose, (int) config('otp.max_verifies', 10) * 4, (int) config('otp.verify_window_minutes', 15), true);
        }

        return DB::transaction(function () use ($hash, $purpose, $code) {
            $otp = Otp::query()->where('verifiable_type', self::TYPE)->where('destination_hash', $hash)->where('purpose', $purpose)
                ->whereNull('consumed_at')->whereNull('invalidated_at')->where('expires_at', '>', now())
                ->lockForUpdate()->latest('id')->first();
            if (! $otp) {
                return false;
            }
            if ($otp->attempts >= $otp->max_attempts) {
                $otp->update(['invalidated_at' => now()]);

                return false;
            }
            $otp->increment('attempts');
            if (! Hash::check($code, $otp->code_hash)) {
                return false;
            }

            return Otp::query()->whereKey($otp->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) === 1;
        });
    }

    protected function guard(string $bucket, string $subject, string $purpose, int $max, int $windowMinutes, bool $hit = false): void
    {
        $key = $this->key($bucket, $subject, $purpose);
        if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
            throw new BusinessRuleException(__('messages.customer.otp_rate_limited'), 429);
        }
        if ($hit) {
            RateLimiter::hit($key, max(1, $windowMinutes) * 60);
        }
    }

    protected function key(string $bucket, string $subject, string $purpose): string
    {
        return "customer-otp:{$bucket}:{$purpose}:".hash('sha256', $subject);
    }
}
