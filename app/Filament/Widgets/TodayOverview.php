<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Support\Fields;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Filament\Widgets\Widget;

/**
 * WAFFRLI dashboard, "Today" (UX redesign 2026-11-11): one compact band of
 * today's figures in the platform timezone, read from the database.
 * Delivered value is summed per order currency (never converted) and shown
 * at each currency's precision; stored amounts are untouched.
 */
class TodayOverview extends Widget
{
    use RefreshesOnOrderEvents;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.today-overview';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    /** @return array{received: int, delivered: int, cancelled: int, avg_minutes: ?int, sales: list<array{code: string, amount: string}>} */
    public static function figures(): array
    {
        $start = now(PlatformSettings::timezone())->startOfDay()->utc();

        $delivered = Order::query()->where('status', OrderStatus::Delivered->value)->where('delivered_at', '>=', $start)
            ->get(['id', 'created_at', 'delivered_at', 'total', 'currency_id']);

        $minutes = $delivered->map(fn (Order $order) => $order->delivered_at && $order->created_at
            ? $order->created_at->diffInMinutes($order->delivered_at) : null)->filter(fn ($m) => $m !== null);

        $currencies = Currency::query()->whereIn('id', $delivered->pluck('currency_id')->unique())->get()->keyBy('id');
        $sales = $delivered->groupBy('currency_id')->map(function ($orders, $currencyId) use ($currencies) {
            $sum = $orders->reduce(fn ($carry, Order $order) => $carry->plus((string) $order->total), Money::of('0'));

            return ['code' => $currencies[$currencyId]?->code ?? '', 'amount' => Fields::formatMoney((string) $sum, $currencies[$currencyId] ?? null)];
        })->sortBy('code')->values()->all();

        return [
            'received' => Order::query()->where('created_at', '>=', $start)->count(),
            'delivered' => $delivered->count(),
            'cancelled' => OrderStatusHistory::query()->where('to_status', OrderStatus::Cancelled->value)->where('created_at', '>=', $start)->count(),
            'avg_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
            'sales' => $sales,
        ];
    }

    protected function getViewData(): array
    {
        return ['f' => self::figures()];
    }
}
