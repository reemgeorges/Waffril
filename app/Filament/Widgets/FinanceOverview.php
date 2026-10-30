<?php

namespace App\Filament\Widgets;

use App\Models\Currency;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use Filament\Widgets\Widget;

/**
 * WAFFRLI (2026-11-01) — wallet and payment totals per currency, computed
 * from the database (amounts are never converted between currencies). The
 * ledger column proves wallet balances equal the immutable ledger.
 */
class FinanceOverview extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.finance-overview';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('payments.view');
    }

    /** @return list<array<string, mixed>> */
    public static function rows(): array
    {
        return Currency::query()->orderByDesc('is_default')->orderBy('code')->get()->map(function (Currency $currency) {
            $balances = (string) (Wallet::query()->where('currency_id', $currency->id)->sum('balance') ?: '0');
            $credits = (string) (WalletTransaction::query()->where('direction', 'credit')->whereHas('wallet', fn ($q) => $q->where('currency_id', $currency->id))->sum('amount') ?: '0');
            $debits = (string) (WalletTransaction::query()->where('direction', 'debit')->whereHas('wallet', fn ($q) => $q->where('currency_id', $currency->id))->sum('amount') ?: '0');
            $payments = OrderPayment::query()->where('currency_id', $currency->id);

            return [
                'code' => $currency->code,
                'wallets' => Wallet::query()->where('currency_id', $currency->id)->where('balance', '>', 0)->count(),
                'wallet_balances' => Money::toStorage($balances),
                'ledger_consistent' => Money::of($credits)->minus($debits)->isEqualTo($balances),
                'collected' => Money::toStorage((string) ((clone $payments)->sum('collected_amount') ?: '0')),
                'pending_cash' => Money::toStorage((string) ((clone $payments)->where('status', OrderPayment::STATUS_PENDING)->whereHas('method', fn ($q) => $q->where('kind', 'cash'))->sum('amount') ?: '0')),
                'refunded' => Money::toStorage((string) (OrderRefund::query()->where('currency_id', $currency->id)->sum('amount') ?: '0')),
                'unsettled_cash' => Money::toStorage((string) ((clone $payments)->whereNotNull('collected_by_driver_id')->whereNull('driver_cash_settlement_id')->sum('collected_amount') ?: '0')),
            ];
        })->filter(fn ($row) => $row['wallet_balances'] !== '0.0000' || $row['collected'] !== '0.0000' || $row['pending_cash'] !== '0.0000')->values()->all();
    }

    protected function getViewData(): array
    {
        return ['rows' => self::rows()];
    }
}
