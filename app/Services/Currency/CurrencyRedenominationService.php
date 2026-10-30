<?php

namespace App\Services\Currency;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\CurrencyRedenomination;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\PlatformPermissions;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — switch the platform DEFAULT currency (e.g. a
 * re-denominated currency) safely.
 *
 * Model: currency exchange_rate = units of that currency per 1 unit of the
 * default. Switching from F to T with r = T.exchange_rate (T per 1 F):
 *   - every CURRENT catalog amount stored in the default currency is
 *     multiplied by r and rounded HALF_UP to T's decimals (branch prices,
 *     option deltas, branch minimum orders, delivery fee rules, FIXED
 *     offers, PLUS display prices / store discounts, and the operational
 *     op_* values of PLUS cycle discounts still expressed in F);
 *   - rates are re-based: T = 1 (new default), every other c = c / r;
 *   - HISTORY IS NEVER TOUCHED: orders, items, payments, refunds, wallets,
 *     ledgers, settlements and PLUS snapshot_* values keep their own
 *     currency and amounts.
 *
 * Safety:
 *   - permission currencies.change_default + feature flag
 *     waffrli.currency.redenomination_enabled (off by default);
 *   - idempotency key (unique); one in-flight run (DB unique marker);
 *   - ONE transaction claims the run, converts everything, re-bases rates
 *     and marks it completed: a crash or error rolls back to the exact
 *     previous state (the run stays `pending` and can be resumed);
 *   - the currencies rows are locked FOR UPDATE; checkout takes a shared
 *     lock on the default currency, so no order is priced half-converted;
 *   - lock timeouts / deadlocks leave the run pending (retry); any other
 *     error marks it failed with the reason; amounts that would round to
 *     zero abort the run before anything changes.
 */
class CurrencyRedenominationService
{
    /** table => [columns, optional where] — CURRENT values expressed in the default currency. */
    public const CATALOG = [
        'branch_products' => [['price'], null],
        'branch_product_options' => [['price_delta'], null],
        'branches' => [['min_order_amount'], null],
        'delivery_fee_rules' => [['base_fee', 'per_km_fee'], null],
        'offers' => [['discount_value'], ['discount_type', 'fixed']],
        'plus_plans' => [['display_price'], null],
        'plus_store_discounts' => [['max_discount_amount', 'min_order_amount'], null],
        'plus_store_discounts#fixed' => [['discount_value'], ['discount_type', 'fixed']],
    ];

    /** Columns that must stay strictly positive when they were positive. */
    public const POSITIVE = ['offers.discount_value', 'plus_store_discounts#fixed.discount_value', 'plus_cycle_discounts.op_discount_value'];

    public static function enabled(): bool
    {
        return (bool) config('waffrli.currency.redenomination_enabled', false);
    }

    public function request(Admin $admin, int $toCurrencyId, string $idempotencyKey): CurrencyRedenomination
    {
        if (! PlatformPermissions::allows($admin, PlatformPermissions::CURRENCIES_CHANGE_DEFAULT)) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
        if (! self::enabled()) {
            throw new BusinessRuleException(__('admin.redenomination.disabled'), 409);
        }
        if ($existing = CurrencyRedenomination::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $from = Currency::query()->default()->firstOrFail();
        $to = Currency::query()->whereKey($toCurrencyId)->first();
        if (! $to || ! $to->is_active || $to->id === $from->id) {
            throw new BusinessRuleException(__('admin.redenomination.invalid_target'), 422);
        }
        $rate = BigDecimal::of((string) $to->exchange_rate);
        if (! $rate->isPositive()) {
            throw new BusinessRuleException(__('admin.redenomination.invalid_target'), 422);
        }

        try {
            $run = CurrencyRedenomination::create([
                'from_currency_id' => $from->id,
                'to_currency_id' => $to->id,
                'conversion_rate' => (string) $rate,
                'status' => CurrencyRedenomination::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'requested_by_admin_id' => $admin->id,
            ]);
        } catch (QueryException $e) {
            if ($existing = CurrencyRedenomination::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing; // same key raced us
            }
            throw new BusinessRuleException(__('admin.redenomination.already_running'), 409);
        }

        AdminAudit::log('currency.redenomination_requested', $run, ['from' => $from->code, 'to' => $to->code, 'rate' => (string) $rate], $admin);

        return $run;
    }

    /** Runs (or resumes) a pending run. Completed runs are returned unchanged. */
    public function execute(CurrencyRedenomination $run): CurrencyRedenomination
    {
        try {
            DB::transaction(function () use ($run) {
                $locked = CurrencyRedenomination::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== CurrencyRedenomination::STATUS_PENDING) {
                    return; // completed or failed: nothing to do (idempotent)
                }
                $startedAt = now();
                $locked->forceFill(['status' => CurrencyRedenomination::STATUS_RUNNING, 'started_at' => $startedAt])->save();

                // Exclusive lock on every currency (fixed order) — serializes with checkout.
                $currencies = Currency::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $from = $currencies->get($locked->from_currency_id);
                $to = $currencies->get($locked->to_currency_id);
                if (! $from?->is_default || ! $to?->is_active || BigDecimal::of((string) $to->exchange_rate)->compareTo((string) $locked->conversion_rate) !== 0) {
                    throw new BusinessRuleException(__('admin.redenomination.stale'), 409);
                }

                $rate = BigDecimal::of((string) $locked->conversion_rate);
                $decimals = min((int) $to->decimals, Money::STORAGE_SCALE);
                $summary = ['from' => $from->code, 'to' => $to->code, 'rate' => (string) $rate, 'decimals' => $decimals, 'tables' => []];

                foreach (self::CATALOG as $key => [$columns, $where]) {
                    $summary['tables'][$key] = $this->convertTable(explode('#', $key)[0], $key, $columns, $where, $rate, $decimals);
                }
                $summary['tables']['plus_cycle_discounts'] = $this->convertCycleDiscounts($from->id, $to->id, $rate, $decimals);
                $summary['rates'] = $this->rebaseRates($currencies, $from, $to, $rate);

                $locked->forceFill([
                    'status' => CurrencyRedenomination::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'summary' => $summary + ['duration_ms' => (int) $startedAt->diffInMilliseconds(now())],
                ])->save();
                AdminAudit::log('currency.redenomination_completed', $locked, ['from' => $from->code, 'to' => $to->code, 'rate' => (string) $rate]);
            });
        } catch (Throwable $e) {
            if (! $this->isTransient($e)) {
                CurrencyRedenomination::query()->whereKey($run->id)->where('status', CurrencyRedenomination::STATUS_PENDING)->update([
                    'status' => CurrencyRedenomination::STATUS_FAILED,
                    'failed_at' => now(),
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
            }
            Log::warning('currency.redenomination_failed', ['run' => $run->id, 'transient' => $this->isTransient($e), 'error' => $e->getMessage()]);
            throw $e;
        }

        return $run->fresh();
    }

    protected function convertTable(string $table, string $key, array $columns, ?array $where, BigDecimal $rate, int $decimals): int
    {
        $rows = 0;
        DB::table($table)->when($where, fn ($q) => $q->where($where[0], $where[1]))->orderBy('id')
            ->lockForUpdate()->select(array_merge(['id'], $columns))
            ->chunkById(500, function ($chunk) use ($table, $key, $columns, $rate, $decimals, &$rows) {
                foreach ($chunk as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} === null) {
                            continue;
                        }
                        $changes[$column] = $this->convert((string) $row->{$column}, $rate, $decimals, "{$key}.{$column}", $row->id);
                    }
                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                        $rows++;
                    }
                }
            });

        return $rows;
    }

    protected function convertCycleDiscounts(int $fromId, int $toId, BigDecimal $rate, int $decimals): int
    {
        $rows = 0;
        DB::table('plus_cycle_discounts')->where('op_currency_id', $fromId)->orderBy('id')->lockForUpdate()
            ->select(['id', 'discount_type', 'op_discount_value', 'op_max_discount_amount', 'op_min_order_amount'])
            ->chunkById(500, function ($chunk) use ($toId, $rate, $decimals, &$rows) {
                foreach ($chunk as $row) {
                    $changes = ['op_currency_id' => $toId];
                    if ($row->discount_type === 'fixed') {
                        $changes['op_discount_value'] = $this->convert((string) $row->op_discount_value, $rate, $decimals, 'plus_cycle_discounts.op_discount_value', $row->id);
                    }
                    foreach (['op_max_discount_amount', 'op_min_order_amount'] as $column) {
                        if ($row->{$column} !== null) {
                            $changes[$column] = $this->convert((string) $row->{$column}, $rate, $decimals, "plus_cycle_discounts.{$column}", $row->id);
                        }
                    }
                    DB::table('plus_cycle_discounts')->where('id', $row->id)->update($changes);
                    $rows++;
                }
            });

        return $rows;
    }

    protected function convert(string $amount, BigDecimal $rate, int $decimals, string $column, int $id): string
    {
        $original = BigDecimal::of($amount);
        $converted = $original->multipliedBy($rate)->toScale($decimals, RoundingMode::HALF_UP);
        if (in_array($column, self::POSITIVE, true) && $original->isPositive() && ! $converted->isPositive()) {
            throw new BusinessRuleException(__('admin.redenomination.rounds_to_zero', ['column' => $column, 'id' => $id]), 422);
        }

        return Money::toStorage($converted);
    }

    /** @return array<string, string> code => new rate */
    protected function rebaseRates($currencies, Currency $from, Currency $to, BigDecimal $rate): array
    {
        $rates = [];
        $now = now();
        // The default marker is unique: release the old default first.
        DB::table('currencies')->where('id', $from->id)->update([
            'is_default' => false,
            'exchange_rate' => (string) BigDecimal::one()->dividedBy($rate, 12, RoundingMode::HALF_UP),
            'rate_updated_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($currencies as $currency) {
            if (in_array($currency->id, [$from->id, $to->id], true)) {
                continue;
            }
            DB::table('currencies')->where('id', $currency->id)->update([
                'exchange_rate' => (string) BigDecimal::of((string) $currency->exchange_rate)->dividedBy($rate, 12, RoundingMode::HALF_UP),
                'rate_updated_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('currencies')->where('id', $to->id)->update(['is_default' => true, 'exchange_rate' => '1', 'rate_updated_at' => $now, 'updated_at' => $now]);

        foreach (DB::table('currencies')->orderBy('id')->get(['code', 'exchange_rate']) as $c) {
            $rates[$c->code] = (string) $c->exchange_rate;
        }

        return $rates;
    }

    protected function isTransient(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1205, 1213], true) // MySQL lock wait timeout / deadlock
            || str_contains(strtolower($e->getMessage()), 'database is locked');
    }
}
