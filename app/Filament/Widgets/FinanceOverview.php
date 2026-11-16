<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Currency\OperationalMoney;
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
    use RefreshesOnOperationalEvents;

    /** Cheap queries: render with the page instead of on scroll. */
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.finance-overview';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('payments.view');
    }

    /** @return list<array<string, mixed>> */
    public static function rows(): array
    {
        // Never summed across currencies. The operational currency (SYP) comes
        // first; any other row is a historical or future currency of its own.
        $operationalId = OperationalMoney::currency()?->id;

        // 2026-11-16 (Phase 14): one grouped query per figure instead of eight
        // queries per currency. Same SQL aggregates, so the same values.
        $byCurrency = fn ($query, string $aggregate) => $query->toBase()
            ->selectRaw("currency_id, {$aggregate} AS aggregate")->groupBy('currency_id')->pluck('aggregate', 'currency_id');

        $balances = $byCurrency(Wallet::query(), 'SUM(balance)');
        $walletsWithBalance = $byCurrency(Wallet::query()->where('balance', '>', 0), 'COUNT(*)');
        $ledger = WalletTransaction::query()->toBase()
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->selectRaw('wallets.currency_id AS currency_id, wallet_transactions.direction AS direction, SUM(wallet_transactions.amount) AS aggregate')
            ->groupBy('wallets.currency_id', 'wallet_transactions.direction')
            ->get()->groupBy('direction')->map(fn ($rows) => $rows->pluck('aggregate', 'currency_id'));
        $collected = $byCurrency(OrderPayment::query(), 'SUM(collected_amount)');
        $pendingCash = $byCurrency(OrderPayment::query()->where('status', OrderPayment::STATUS_PENDING)->whereHas('method', fn ($q) => $q->where('kind', 'cash')), 'SUM(amount)');
        $refunded = $byCurrency(OrderRefund::query(), 'SUM(amount)');
        $unsettled = $byCurrency(OrderPayment::query()->whereNotNull('collected_by_driver_id')->whereNull('driver_cash_settlement_id'), 'SUM(collected_amount)');

        $value = fn ($map, int $id) => (string) (($map[$id] ?? null) ?: '0');

        return Currency::query()->orderBy('code')->get()->sortBy(fn (Currency $c) => $c->id === $operationalId ? 0 : 1)->values()->map(function (Currency $currency) use ($operationalId, $balances, $walletsWithBalance, $ledger, $collected, $pendingCash, $refunded, $unsettled, $value) {
            $id = $currency->id;
            $balance = $value($balances, $id);

            return [
                'code' => $currency->code,
                'historical' => $operationalId !== null && $id !== $operationalId,
                'wallets' => (int) ($walletsWithBalance[$id] ?? 0),
                'wallet_balances' => Money::toStorage($balance),
                'ledger_consistent' => Money::of($value($ledger['credit'] ?? [], $id))->minus($value($ledger['debit'] ?? [], $id))->isEqualTo($balance),
                'collected' => Money::toStorage($value($collected, $id)),
                'pending_cash' => Money::toStorage($value($pendingCash, $id)),
                'refunded' => Money::toStorage($value($refunded, $id)),
                'unsettled_cash' => Money::toStorage($value($unsettled, $id)),
            ];
        })->filter(fn ($row) => $row['wallet_balances'] !== '0.0000' || $row['collected'] !== '0.0000' || $row['pending_cash'] !== '0.0000')->values()->all();
    }

    /**
     * Display only (UX redesign 2026-11-11): each amount at its currency's
     * precision with thousands separators; rows() keeps the stored values.
     */
    protected function getViewData(): array
    {
        $currencies = Currency::query()->get()->keyBy('code');

        return ['rows' => array_map(function (array $row) use ($currencies) {
            $currency = $currencies[$row['code']] ?? null;
            $row['display'] = collect(['wallet_balances', 'collected', 'pending_cash', 'refunded', 'unsettled_cash'])
                // The currency is its own column: the numbers only.
                ->mapWithKeys(fn ($key) => [$key => Fields::formatAmount($row[$key], $currency)])->all();

            return $row;
        }, self::rows())];
    }
}
