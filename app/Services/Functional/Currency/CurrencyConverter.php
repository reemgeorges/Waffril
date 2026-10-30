<?php

namespace App\Services\Functional\Currency;

use App\Models\Currency;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * WAFFRLI P0 (2026-10-30) — converts an amount between any two
 * currencies through the default currency:
 *
 *   amount_to = amount_from * (rate_to / rate_from)
 *
 * where every rate is "units per 1 default unit". The cross rate is
 * computed at RATE_SCALE (12) and the result rounded half-up to the
 * target currency's own decimals. Display-only conversions and (later)
 * checkout conversions both go through here; stored prices are never
 * rewritten by a conversion.
 */
class CurrencyConverter
{
    public function convert(BigDecimal|int|string $amount, Currency $from, Currency $to): BigDecimal
    {
        if ($from->is($to)) {
            return Money::roundTo($amount, (int) $to->decimals);
        }

        return Money::roundTo(
            Money::of($amount)->multipliedBy($this->crossRate($from, $to)),
            (int) $to->decimals
        );
    }

    public function crossRate(Currency $from, Currency $to): BigDecimal
    {
        $fromRate = BigDecimal::of((string) $from->exchange_rate);
        $toRate = BigDecimal::of((string) $to->exchange_rate);

        if ($fromRate->isLessThanOrEqualTo(0) || $toRate->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Exchange rates must be positive.');
        }

        return $toRate->dividedBy($fromRate, Money::RATE_SCALE, RoundingMode::HALF_UP);
    }
}
