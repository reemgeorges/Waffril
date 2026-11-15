<?php

namespace App\Support\Money;

use App\Models\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * WAFFRLI (2026-11-15) — the ONE place where a currency's STORED unit and
 * its DISPLAYED unit differ (the 2026 Syrian pound redenomination:
 * 100 old SYP = 1 new SYP). This is a fixed legal ratio, NOT an exchange
 * rate, and it never touches stored data.
 *
 * config('waffrli.currency.denominations') declares, per currency code:
 *  - stored:  the denomination the database rows are expressed in. The SYP
 *             row carries rate 13000 per USD with 0 decimals, so every
 *             stored SYP amount (catalog conversions, orders, payments,
 *             wallets, refunds, settlements, rates) is in OLD pounds;
 *  - display: the denomination people see and type (NEW pounds);
 *  - units:   old pounds per unit of each denomination (old 1, new 100);
 *  - decimals: display precision (new pound: 2 = qirsh).
 *
 * present() divides a stored amount once on the way OUT (API, Filament);
 * toStored() multiplies a typed amount once on the way IN. Both are exact:
 * 1 old pound = 1 qirsh, so no amount is ever rounded. When stored and
 * display are the same (every other currency, or SYP after a future
 * redenomination of the row), the factor is 1: nothing is converted twice.
 */
final class Denomination
{
    /** Stored units per displayed unit (100 for SYP old → new; 1 otherwise). */
    public static function factor(Currency|string|null $currency): BigDecimal
    {
        $config = self::config($currency);
        if ($config === null) {
            return BigDecimal::one();
        }
        $units = $config['units'];

        return BigDecimal::of((string) $units[$config['display']])->dividedBy((string) $units[$config['stored']], 12, RoundingMode::UNNECESSARY)->stripTrailingZeros();
    }

    public static function converts(Currency|string|null $currency): bool
    {
        return ! self::factor($currency)->isEqualTo(1);
    }

    /** The displayed denomination's name (e.g. "new"), null when none is configured. */
    public static function displayName(Currency|string|null $currency): ?string
    {
        return self::config($currency)['display'] ?? null;
    }

    /** The stored denomination's name (e.g. "old"), null when none is configured. */
    public static function storedName(Currency|string|null $currency): ?string
    {
        return self::config($currency)['stored'] ?? null;
    }

    /** Display decimals: the configured ones for a redenominated currency, else the row's own. */
    public static function decimals(Currency|string|null $currency, ?int $fallback = 2): ?int
    {
        $config = self::config($currency);
        if ($config !== null && isset($config['decimals'][$config['display']])) {
            return (int) $config['decimals'][$config['display']];
        }

        return $currency instanceof Currency && $currency->decimals !== null ? (int) $currency->decimals : $fallback;
    }

    /**
     * A stored amount in the displayed denomination, exact (never rounded
     * away): at least the display decimals, more only if the stored value
     * really has them (e.g. a 4-decimal ledger remainder).
     */
    public static function present(BigDecimal|int|string|null $stored, Currency|string|null $currency): ?BigDecimal
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $value = Money::of((string) $stored)->dividedBy(self::factor($currency), 12, RoundingMode::UNNECESSARY);
        $decimals = (int) self::decimals($currency);
        $exact = $value->stripTrailingZeros();

        return $exact->getScale() <= $decimals ? $exact->toScale($decimals) : $exact;
    }

    /** present() as an API string ("325.00"); null stays null. */
    public static function presentString(BigDecimal|int|string|null $stored, Currency|string|null $currency): ?string
    {
        $value = self::present($stored, $currency);

        return $value === null ? null : (string) $value;
    }

    /**
     * A typed amount (displayed denomination) in stored units; null when it
     * has more decimals than the displayed denomination allows (then it is
     * not a valid amount to type).
     */
    public static function toStored(BigDecimal|int|string $typed, Currency|string|null $currency): ?BigDecimal
    {
        $value = Money::of((string) $typed);
        $decimals = (int) self::decimals($currency, 4);
        if (! $value->isEqualTo($value->toScale($decimals, RoundingMode::DOWN))) {
            return null;
        }

        return $value->multipliedBy(self::factor($currency))->stripTrailingZeros();
    }

    /**
     * An exchange rate (units of $currency per base unit, as stored) in the
     * displayed denomination: 13000 old SYP per USD → 130 new SYP per USD.
     */
    public static function presentRate(BigDecimal|int|string|null $storedRate, Currency|string|null $currency): ?string
    {
        if ($storedRate === null || $storedRate === '') {
            return null;
        }

        return (string) Money::of((string) $storedRate)->dividedBy(self::factor($currency), 12, RoundingMode::UNNECESSARY)->stripTrailingZeros();
    }

    /** API currency metadata in the displayed denomination. */
    public static function currencyPayload(Currency $currency): array
    {
        return [
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'decimals' => self::decimals($currency),
            'denomination' => self::displayName($currency),
        ];
    }

    /** @return array{stored: string, display: string, units: array<string, int|string>, decimals: array<string, int>}|null */
    protected static function config(Currency|string|null $currency): ?array
    {
        $code = strtoupper((string) ($currency instanceof Currency ? $currency->code : $currency));
        $config = config("waffrli.currency.denominations.{$code}");
        if (! is_array($config) || ! isset($config['stored'], $config['display'], $config['units'][$config['stored']], $config['units'][$config['display']])) {
            return null;
        }

        return $config;
    }
}
