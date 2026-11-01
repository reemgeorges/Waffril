<?php

namespace App\Support\Phone;

/**
 * WAFFRLI (2026-11-03) — E.164 normalization for customer phones.
 * "+963 912-345-678", "00963912345678" and "0912345678" (with the default
 * country code 963) all become "+963912345678". Returns null when the
 * result is not a plausible E.164 number.
 */
final class PhoneNumber
{
    public static function normalize(?string $input, ?string $defaultCountryCode = null): ?string
    {
        if ($input === null) {
            return null;
        }
        $value = preg_replace('/[\s\-().]/', '', trim($input));
        if ($value === '' || ! preg_match('/^\+?\d+$/', $value)) {
            return null;
        }

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        } elseif (! str_starts_with($value, '+')) {
            $country = ltrim($defaultCountryCode ?? (string) config('waffrli.customer.default_country_code', '963'), '+');
            $value = '+'.$country.ltrim($value, '0');
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $value) ? $value : null;
    }
}
