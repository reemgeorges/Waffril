<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Auth\AdminPermissions;
use Filament\Widgets\ChartWidget;

/**
 * WAFFRLI (2026-11-01) — number of orders in each status (database counts).
 */
class OrdersByStatusChart extends ChartWidget
{
    use \App\Filament\Concerns\RefreshesOnOrderEvents;

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    public function getHeading(): ?string
    {
        return __('admin.dashboard_stats.orders_by_status');
    }

    /** @return array<string, int> status value => count, every status present */
    public static function counts(): array
    {
        $counts = Order::query()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    protected function getData(): array
    {
        $counts = self::counts();
        $colours = ['pending' => '#f59e0b', 'accepted' => '#0ea5e9', 'assigned' => '#d97706', 'picked_up' => '#2563eb', 'delivered' => '#16a34a', 'cancelled' => '#dc2626'];

        return [
            'datasets' => [[
                'label' => __('admin.dashboard_stats.orders'),
                'data' => array_values($counts),
                'backgroundColor' => array_map(fn ($status) => $colours[$status], array_keys($counts)),
            ]],
            'labels' => array_map(fn ($status) => OrderStatus::from($status)->label(), array_keys($counts)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
