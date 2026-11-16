<?php

namespace App\Services\Monitoring;

/**
 * WAFFRLI (2026-11-16) — removes secrets and personal data from error text
 * before it is stored or shown: bearer/JWT/API tokens, key=value secrets
 * (password, token, otp, code, secret, authorization…), PEM keys, e-mail
 * addresses, phone numbers and long opaque strings. Conservative on
 * purpose: a little lost detail is better than a leaked OTP.
 */
final class Scrubber
{
    public static function text(string $text): string
    {
        $rules = [
            '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s' => '[key]',
            '/\bBearer\s+[A-Za-z0-9._~+\/=|-]+/i' => 'Bearer [token]',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => '[jwt]',
            '/\bya29\.[A-Za-z0-9._-]+/' => '[token]',
            '/\b\d+\|[A-Za-z0-9]{20,}/' => '[token]',                       // Sanctum plain-text tokens
            '/(["\']?(?:password|passwd|pwd|secret|token|api[_-]?key|otp|code|pin|authorization|private[_-]?key|access[_-]?token|refresh[_-]?token)["\']?\s*[:=]>?\s*)(["\']?)[^\s"\',&}]+\2/i' => '$1$2[redacted]$2',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[email]',
            '/(?<![\w.])\+?\d[\d\s-]{7,}\d(?![\w.])/' => '[phone]',
            '/\b[A-Za-z0-9+\/_-]{40,}={0,2}/' => '[redacted]',
        ];

        return preg_replace(array_keys($rules), array_values($rules), $text) ?? '';
    }

    /** Message normalised for grouping: numbers and quoted values do not split a group. */
    public static function normalise(string $message): string
    {
        $message = preg_replace('/(["\']).*?\1/', '?', $message) ?? $message;

        return preg_replace('/\d+/', 'N', $message) ?? $message;
    }
}
