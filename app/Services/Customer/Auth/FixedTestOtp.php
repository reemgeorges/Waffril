<?php

namespace App\Services\Customer\Auth;

use App\Support\Phone\PhoneNumber;

/**
 * WAFFRLI (2026-11-16) — optional fixed customer OTP for LOCAL app testing
 * (config waffrli.dev.fixed_otp). It replaces only the randomly generated
 * code of an allowlisted test phone; everything else in PhoneOtpService
 * stays the same (hashed storage, expiry, cooldown, rate limits, attempt
 * ceiling, purpose isolation, single atomic use).
 *
 * Never active in production, whatever the flags say. Never active for a
 * phone outside the allowlist: a local server may be public through a
 * Cloudflare tunnel, so a universal code would open real accounts.
 * The code itself is never logged or returned.
 */
final class FixedTestOtp
{
    /** Stored in otps.channel for the audit trail (the API keeps answering with the normal channel). */
    public const CHANNEL = 'test_fixed';

    public const ENVIRONMENTS = ['local', 'testing'];

    /** The fixed code for this phone, or null when the normal random code must be used. */
    public static function codeFor(string $phone): ?string
    {
        if (! self::environmentAllows()) {
            return null;
        }
        $config = (array) config('waffrli.dev.fixed_otp', []);
        $code = (string) ($config['code'] ?? '');
        if (! preg_match('/^\d{4,8}$/', $code)) {
            return null;
        }

        return in_array(self::normalize($phone), self::allowlist(), true) ? $code : null;
    }

    public static function environmentAllows(): bool
    {
        return (bool) config('waffrli.dev.fixed_otp.enabled')
            && ! app()->environment('production')
            && app()->environment(self::ENVIRONMENTS);
    }

    /** @return list<string> normalized E.164 numbers */
    public static function allowlist(): array
    {
        return collect((array) config('waffrli.dev.fixed_otp.phones', []))
            ->map(fn ($phone) => self::normalize((string) $phone))
            ->filter()->unique()->values()->all();
    }

    protected static function normalize(string $phone): ?string
    {
        return PhoneNumber::normalize($phone);
    }
}
