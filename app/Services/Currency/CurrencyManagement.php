<?php

namespace App\Services\Currency;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Currency;
use App\Services\Audit\AdminAudit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — currency rules shared by the P0 admin API
 * (App\Services\Model\Currency\CurrencyService) and the Filament panel.
 *
 * - A non-default currency can only exist once a default exists (its rate
 *   means "units per 1 default unit").
 * - initializeDefault() only while NO default exists.
 * - Switching an existing default is NOT available: it needs the
 *   controlled re-denomination job (P7), which is not implemented. There is
 *   deliberately no method here that performs a switch.
 * - The default's rate is fixed at 1 and it cannot be deactivated; `code`
 *   is immutable.
 */
class CurrencyManagement
{
    public function create(array $data, ?Admin $admin = null): Currency
    {
        return DB::transaction(function () use ($data, $admin) {
            $hasDefault = Currency::query()->default()->lockForUpdate()->exists();
            if (! $hasDefault && Currency::query()->exists()) {
                throw new BusinessRuleException(__('messages.currency_default_required_first'));
            }

            unset($data['is_default']);
            $data['code'] = strtoupper((string) $data['code']);
            $data['rate_updated_at'] = now();
            $data['rate_updated_by_admin_id'] = $admin?->getKey();

            $currency = Currency::create($data);
            AdminAudit::log('currency.created', $currency, ['code' => $currency->code, 'exchange_rate' => (string) $currency->exchange_rate], $admin);

            return $currency;
        });
    }

    public function update(Currency $currency, array $data, ?Admin $admin = null): Currency
    {
        return DB::transaction(function () use ($currency, $data, $admin) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->getKey());
            unset($data['id'], $data['code'], $data['is_default'], $data['is_active']);

            if ($currency->is_default && array_key_exists('exchange_rate', $data)) {
                if (! BigDecimal::of((string) $data['exchange_rate'])->isEqualTo(1)) {
                    throw new BusinessRuleException(__('messages.currency_default_rate_fixed'));
                }
                unset($data['exchange_rate']);
            }

            $rateChanged = array_key_exists('exchange_rate', $data)
                && ! BigDecimal::of((string) $data['exchange_rate'])->isEqualTo((string) $currency->exchange_rate);

            if ($rateChanged) {
                $data['rate_updated_at'] = now();
                $data['rate_updated_by_admin_id'] = $admin?->getKey();
            }

            $before = (string) $currency->exchange_rate;
            $currency->update($data);

            AdminAudit::log('currency.updated', $currency, array_filter([
                'rate_before' => $rateChanged ? $before : null,
                'rate_after' => $rateChanged ? (string) $currency->exchange_rate : null,
            ]), $admin);

            return $currency;
        });
    }

    public function setActive(Currency $currency, bool $active, ?Admin $admin = null): Currency
    {
        return DB::transaction(function () use ($currency, $active, $admin) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->getKey());
            if (! $active && $currency->is_default) {
                throw new BusinessRuleException(__('messages.currency_default_cannot_deactivate'));
            }

            $currency->update(['is_active' => $active]);
            AdminAudit::log($active ? 'currency.activated' : 'currency.deactivated', $currency, [], $admin);

            return $currency;
        });
    }

    public function initializeDefault(Currency $currency, ?Admin $admin = null): Currency
    {
        return DB::transaction(function () use ($currency, $admin) {
            if (Currency::query()->default()->lockForUpdate()->exists()) {
                throw new BusinessRuleException(__('messages.currency_default_already_initialized'), 409);
            }

            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->getKey());
            $currency->forceFill([
                'is_default' => true,
                'is_active' => true,
                'exchange_rate' => '1',
                'rate_updated_at' => now(),
                'rate_updated_by_admin_id' => $admin?->getKey(),
            ])->save();

            AdminAudit::log('currency.default_initialized', $currency, ['code' => $currency->code], $admin);

            return $currency;
        });
    }

    public static function hasDefault(): bool
    {
        return Currency::query()->default()->exists();
    }

    /** Re-denomination (switching an existing default) is not implemented yet. */
    public static function canSwitchDefault(): bool
    {
        return false;
    }
}
