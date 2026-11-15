<?php

namespace App\Services\Currency;

use App\Models\Currency;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Once;
use Throwable;

/**
 * WAFFRLI (2026-11-15) — the ONE boundary between amounts stored in the
 * accounting base currency (catalog prices, option deltas, minimum orders,
 * delivery fee rules, fixed discounts, PLUS amounts) and the operational
 * currency people see and type (CurrencyPolicy: SYP at launch).
 *
 * - Storage is never rewritten: base columns keep their currency; this
 *   class only converts at display and at admin input.
 * - fromBase() uses the same CurrencyConverter (same rate, HALF_UP to the
 *   operational decimals) as the customer catalog and checkout, so the
 *   admin panel shows exactly what customers are charged.
 * - toBase() is the exact inverse: it returns a storable base amount
 *   (DECIMAL(18,4)) only when converting it back yields the typed amount
 *   unchanged. Otherwise it reports the nearest amounts that ARE storable,
 *   so an admin entry can never drift.
 * - Admin input in the operational currency is allowed only when the
 *   stored rate equals the explicitly approved one
 *   (WAFFRLI_APPROVED_OPERATIONAL_RATE). A demonstration rate is never
 *   treated as approved implicitly, and changing the stored rate revokes
 *   the approval until the configuration is updated.
 * - When base and operational currency are the same (for example after a
 *   future accounting switch), everything is the identity.
 */
final class OperationalMoney
{
    /** The accounting base currency (Currency.is_default). */
    public static function base(): ?Currency
    {
        return self::context()['base'];
    }

    /** The operational currency (CurrencyPolicy), or null when not offered/active. */
    public static function currency(): ?Currency
    {
        return self::context()['operational'];
    }

    /** Units of the operational currency per base unit; null when missing or invalid. */
    public static function rate(): ?BigDecimal
    {
        return self::context()['rate'];
    }

    public static function sameAsBase(): bool
    {
        $base = self::base();
        $operational = self::currency();

        return $base !== null && $operational !== null && $base->is($operational);
    }

    /** The configured approved rate, verbatim (null when none is configured). */
    public static function approvedRate(): ?string
    {
        $value = trim((string) config('waffrli.currency.approved_operational_rate', ''));

        return $value === '' ? null : $value;
    }

    /** True when the stored rate is exactly the approved one (always true for the identity). */
    public static function rateApproved(): bool
    {
        if (self::sameAsBase()) {
            return true;
        }
        $rate = self::rate();
        $approved = self::approvedRate();
        if ($rate === null || $approved === null) {
            return false;
        }
        try {
            // Approved in the DISPLAYED denomination (130 new SYP), stored in
            // the stored one (13000 old SYP): compared through the fixed ratio.
            return BigDecimal::of($approved)->multipliedBy(Denomination::factor(self::currency()))->isEqualTo($rate);
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether admins type operational amounts (otherwise they type base amounts). */
    public static function inputEnabled(): bool
    {
        return self::currency() !== null && self::rate() !== null && self::rateApproved();
    }

    /**
     * A base amount in the operational currency, rounded to its decimals;
     * null when the operational currency or its rate is unavailable.
     */
    public static function fromBase(BigDecimal|int|string|null $amount): ?BigDecimal
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $base = self::base();
        $operational = self::currency();
        if ($base === null || $operational === null || self::rate() === null) {
            return null;
        }

        return app(CurrencyConverter::class)->convert(Money::of((string) $amount), $base, $operational);
    }

    /**
     * An amount stored in $source (e.g. a PLUS cycle's op_currency) in the
     * operational currency. Same currency → only rounded, never converted.
     */
    public static function fromCurrency(BigDecimal|int|string|null $amount, ?Currency $source): ?BigDecimal
    {
        if ($amount === null || $amount === '' || $source === null) {
            return null;
        }
        $operational = self::currency();
        if ($operational === null) {
            return null;
        }
        try {
            return app(CurrencyConverter::class)->convert(Money::of((string) $amount), $source, $operational);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Exact inverse of fromBase() for an amount TYPED in the displayed
     * denomination (new SYP): converted to stored units by the fixed
     * denomination ratio first, then to the base. lower/upper are in STORED
     * units (format them with Fields::formatMoney, which presents them).
     *
     * @return array{exact: ?string, lower: ?BigDecimal, upper: ?BigDecimal}
     *                                                                       exact: storable base amount (4 decimals) when one converts back to $amount;
     *                                                                       lower/upper: the nearest operational amounts that can be stored otherwise.
     */
    public static function toBase(BigDecimal|int|string $amount): array
    {
        $operational = self::currency();
        $rate = self::rate();
        $typed = Money::of((string) $amount);
        if ($operational === null || $rate === null || $typed->isNegative()) {
            return ['exact' => null, 'lower' => null, 'upper' => null];
        }
        $target = Denomination::toStored($typed, $operational);
        if ($target === null || ! $target->isEqualTo(Money::roundTo($target, (int) $operational->decimals))) {
            // More decimals than the displayed denomination has: never storable as typed.
            $decimals = (int) Denomination::decimals($operational);

            return ['exact' => null,
                'lower' => Denomination::toStored($typed->toScale($decimals, RoundingMode::FLOOR), $operational),
                'upper' => Denomination::toStored($typed->toScale($decimals, RoundingMode::CEILING), $operational)];
        }
        if (self::sameAsBase()) {
            return ['exact' => Money::toStorage($target), 'lower' => null, 'upper' => null];
        }

        $step = BigDecimal::of('0.0001');
        $center = $target->dividedBy($rate, Money::STORAGE_SCALE, RoundingMode::HALF_UP);
        $lower = null;
        $upper = null;
        // The center is the closest storable amount, so it wins when it matches.
        foreach ([0, -1, 1, -2, 2] as $offset) {
            $candidate = $center->plus($step->multipliedBy($offset));
            if ($candidate->isNegative()) {
                continue;
            }
            $back = self::fromBase($candidate);
            if ($back === null) {
                continue;
            }
            if ($back->isEqualTo($target)) {
                return ['exact' => Money::toStorage($candidate), 'lower' => null, 'upper' => null];
            }
            if ($back->isLessThan($target) && ($lower === null || $back->isGreaterThan($lower))) {
                $lower = $back;
            }
            if ($back->isGreaterThan($target) && ($upper === null || $back->isLessThan($upper))) {
                $upper = $back;
            }
        }

        return ['exact' => null, 'lower' => $lower, 'upper' => $upper];
    }

    /** Clears the per-request currency context (after a rate change, in tests). */
    public static function flush(): void
    {
        Once::flush();
    }

    /** @return array{base: ?Currency, operational: ?Currency, rate: ?BigDecimal} */
    private static function context(): array
    {
        return once(function () {
            $base = Currency::query()->default()->first();
            $operational = CurrencyPolicy::displayCurrency(null);
            $rate = null;
            if ($base !== null && $operational !== null) {
                try {
                    $rate = app(CurrencyConverter::class)->crossRate($base, $operational);
                } catch (Throwable) {
                    $rate = null;
                }
            }

            return ['base' => $base, 'operational' => $operational, 'rate' => $rate];
        });
    }
}
