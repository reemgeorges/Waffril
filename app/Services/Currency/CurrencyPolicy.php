<?php

namespace App\Services\Currency;

use App\Exceptions\BusinessRuleException;
use App\Models\Currency;
use App\Models\User;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Support\Money\Denomination;
use Illuminate\Support\Collection;

/** Operational currencies are independent of the accounting base currency. */
class CurrencyPolicy
{
    public static function operationalAmount(?string $amount, ?int $sourceCurrencyId = null): ?string
    {
        if ($amount === null) {
            return null;
        }
        $source = $sourceCurrencyId ? Currency::find($sourceCurrencyId) : Currency::query()->default()->first();
        $target = self::displayCurrency(null);
        if (! $source || ! $target) {
            throw new BusinessRuleException(__('messages.customer.currency_not_payable'), 422);
        }

        // API amount: converted to the operational currency, then shown in its
        // displayed denomination (new SYP), exactly once.
        return Denomination::presentString(app(CurrencyConverter::class)->convert($amount, $source, $target), $target);
    }

    /** Active currencies offered to customers, the default first. */
    public static function customerCurrencies(): Collection
    {
        $codes = array_map('strtoupper', (array) config('waffrli.currency.customer_currencies', []));

        return Currency::query()
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();
    }

    public static function isCustomerCurrency(?Currency $currency): bool
    {
        return $currency !== null && self::customerCurrencies()->contains('id', $currency->id);
    }

    /** True when customers can choose between more than one currency. */
    public static function customerCanChoose(): bool
    {
        return self::customerCurrencies()->count() > 1;
    }

    /** The currency a customer browses in: their choice when still offered, else the default. */
    public static function displayCurrency(?User $user): ?Currency
    {
        $offered = self::customerCurrencies();
        $chosen = $user?->display_currency_id ? $offered->firstWhere('id', $user->display_currency_id) : null;

        return $chosen ?? $offered->firstWhere('code', strtoupper((string) config('waffrli.currency.operational_default', 'SYP')));
    }

    /**
     * The currency an order is priced and paid in: an explicitly requested
     * one must be offered (422 otherwise); without a request, the
     * customer's display currency.
     */
    public static function paymentCurrency(User $user, ?int $requestedId): Currency
    {
        if ($requestedId !== null) {
            $currency = self::customerCurrencies()->firstWhere('id', $requestedId);
            if (! $currency) {
                throw new BusinessRuleException(__('messages.customer.currency_not_payable'), 422);
            }

            return $currency;
        }
        $currency = self::displayCurrency($user);
        if (! $currency) {
            throw new BusinessRuleException(__('messages.customer.currency_not_payable'), 422);
        }

        return $currency;
    }
}
