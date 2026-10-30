<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * WAFFRLI P0 (2026-10-30) — exact decimal arithmetic for money.
 *
 * Uses brick/math's BigDecimal (already installed as a locked dependency
 * of laravel/framework; ext-bcmath is not available in this environment)
 * so no amount ever passes through a PHP float. Amounts are stored as
 * DECIMAL(18,4) and rounded half-up to the currency's own `decimals`
 * only when a final, payable value is produced.
 */
final class Money
{
    /** Storage scale of every money column (DECIMAL(18,4)). */
    public const STORAGE_SCALE = 4;

    /** Scale of every exchange-rate column (DECIMAL(24,12)). */
    public const RATE_SCALE = 12;

    public static function of(BigDecimal|int|string $amount): BigDecimal
    {
        return BigDecimal::of($amount);
    }

    public static function toStorage(BigDecimal|int|string $amount): string
    {
        return (string) self::of($amount)->toScale(self::STORAGE_SCALE, RoundingMode::HALF_UP);
    }

    public static function roundTo(BigDecimal|int|string $amount, int $decimals): BigDecimal
    {
        return self::of($amount)->toScale($decimals, RoundingMode::HALF_UP);
    }
}
