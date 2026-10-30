<?php

namespace App\Services\Driver;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\DriverCashSettlement;
use App\Models\OrderPayment;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — daily driver → platform cash reconciliation,
 * one record per driver, currency and business day (platform timezone).
 *
 * prepare(): links every cash payment the driver actually collected that
 * day and is not yet settled; expected = Σ collected_amount.
 * settle(): records the cash received; equal → settled, different →
 * disputed (note required). resolveDispute(): closes a disputed day.
 * Settled records are final. There is no merchant settlement.
 */
class DriverCashSettlementService
{
    public function prepare(Driver $driver, Currency $currency, string $businessDate, Admin $admin): DriverCashSettlement
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($driver, $currency, $businessDate, $admin) {
            $settlement = DriverCashSettlement::query()->firstOrCreate(
                ['driver_id' => $driver->id, 'currency_id' => $currency->id, 'business_date' => $businessDate],
                ['status' => DriverCashSettlement::STATUS_OPEN, 'expected_amount' => 0]
            );
            $settlement = DriverCashSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($settlement->status === DriverCashSettlement::STATUS_SETTLED) {
                throw new BusinessRuleException(__('admin.errors.settlement_closed'));
            }

            $tz = PlatformSettings::timezone();
            $start = Carbon::parse($businessDate, $tz)->startOfDay()->utc();
            $end = $start->copy()->addDay();

            OrderPayment::query()
                ->where('collected_by_driver_id', $driver->id)
                ->where('currency_id', $currency->id)
                ->whereNull('driver_cash_settlement_id')
                ->where('collected_amount', '>', 0)
                ->whereHas('method', fn ($q) => $q->where('kind', 'cash'))
                ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
                ->lockForUpdate()
                ->update(['driver_cash_settlement_id' => $settlement->id, 'updated_at' => now()]);

            $expected = (string) (OrderPayment::query()->where('driver_cash_settlement_id', $settlement->id)->sum('collected_amount') ?: '0');
            $settlement->update(['expected_amount' => Money::toStorage($expected)]);

            AdminAudit::log('settlement.prepared', $settlement, ['expected' => Money::toStorage($expected)], $admin);

            return $settlement->refresh();
        });
    }

    public function settle(DriverCashSettlement $settlement, string $received, ?string $note, Admin $admin): DriverCashSettlement
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($settlement, $received, $note, $admin) {
            $settlement = DriverCashSettlement::query()->lockForUpdate()->findOrFail($settlement->getKey());
            if ($settlement->status !== DriverCashSettlement::STATUS_OPEN) {
                throw new BusinessRuleException(__('admin.errors.settlement_not_open'));
            }

            $value = Money::of($received);
            if ($value->isLessThan(0)) {
                throw new BusinessRuleException(__('admin.errors.amount_must_be_positive'));
            }

            $matches = $value->isEqualTo((string) $settlement->expected_amount);
            if (! $matches && blank($note)) {
                throw new BusinessRuleException(__('admin.errors.discrepancy_note_required'));
            }

            $settlement->update([
                'received_amount' => Money::toStorage($value),
                'status' => $matches ? DriverCashSettlement::STATUS_SETTLED : DriverCashSettlement::STATUS_DISPUTED,
                'settled_by_admin_id' => $admin->id,
                'settled_at' => $matches ? now() : null,
                'note' => $note,
            ]);

            AdminAudit::log('settlement.recorded', $settlement, ['received' => Money::toStorage($value), 'status' => $settlement->status], $admin);

            return $settlement;
        });
    }

    public function resolveDispute(DriverCashSettlement $settlement, string $note, Admin $admin): DriverCashSettlement
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($settlement, $note, $admin) {
            $settlement = DriverCashSettlement::query()->lockForUpdate()->findOrFail($settlement->getKey());
            if ($settlement->status !== DriverCashSettlement::STATUS_DISPUTED) {
                throw new BusinessRuleException(__('admin.errors.settlement_not_disputed'));
            }

            $settlement->update([
                'status' => DriverCashSettlement::STATUS_SETTLED,
                'settled_by_admin_id' => $admin->id,
                'settled_at' => now(),
                'note' => trim(($settlement->note ? $settlement->note.' | ' : '').$note),
            ]);

            AdminAudit::log('settlement.dispute_resolved', $settlement, [], $admin);

            return $settlement;
        });
    }

    protected function authorize(Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'settlements.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }
}
