<?php

namespace App\Http\Requests\Model\Platform;

/**
 * WAFFRLI P0 (2026-10-30) — a monetary value is a non-negative decimal
 * with at most 4 fractional digits (storage scale), given as a JSON
 * number or numeric string.
 */
final class MonetaryValue
{
    public static function isValid(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^\d{1,14}(\.\d{1,4})?$/', (string) $value);
    }
}
