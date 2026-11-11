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

    protected static ?int $sort = 2;

    /** One third of a wide dashboard, beside the pending queue. */
    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected ?string $maxHeight = '300px';

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
        // Same meaning as the status badges (OrderStatus::color()): waiting
        // amber, confirmed blue, on the road indigo (light: assigned, deep:
        // picked up; same as the driver map), done green, cancelled red.
        $colours = ['pending' => '#f59e0b', 'accepted' => '#3b82f6', 'assigned' => '#a5b4fc', 'picked_up' => '#6366f1', 'delivered' => '#10b981', 'cancelled' => '#ef4444'];

        return [
            'datasets' => [[
                'label' => __('admin.dashboard_stats.orders'),
                'data' => array_values($counts),
                'backgroundColor' => array_map(fn ($status) => $colours[$status], array_keys($counts)),
                'borderWidth' => 0,
                'borderRadius' => 4,
                'maxBarThickness' => 22,
                'barPercentage' => 0.8,
            ]],
            'labels' => array_map(fn ($status) => OrderStatus::from($status)->label(), array_keys($counts)),
        ];
    }

    /**
     * Horizontal bars: the status names read straight on the side axis
     * (no rotated labels) and the bars grow from the reading edge (right in
     * Arabic, left in English). Quiet axes; the bars carry the meaning.
     */
    protected function getOptions(): array
    {
        $grid = 'rgba(148, 163, 184, 0.12)';
        $text = 'rgba(148, 163, 184, 0.95)';
        $rtl = app()->getLocale() === 'ar';
        $font = ['family' => "'IBM Plex Sans Arabic', system-ui, sans-serif", 'size' => 12];

        return [
            'indexAxis' => 'y',
            // Taller than Filament's default 2:1 so six bars breathe (capped by $maxHeight).
            'aspectRatio' => 1.3,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['beginAtZero' => true, 'reverse' => $rtl, 'grid' => ['color' => $grid], 'border' => ['display' => false],
                    'ticks' => ['color' => $text, 'precision' => 0, 'font' => $font]],
                'y' => ['position' => $rtl ? 'right' : 'left', 'grid' => ['display' => false], 'border' => ['display' => false],
                    'ticks' => ['color' => $text, 'font' => $font + ['weight' => 500]]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
