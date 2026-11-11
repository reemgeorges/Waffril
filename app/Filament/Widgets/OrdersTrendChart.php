<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Models\Order;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI dashboard (UX redesign 2026-11-11): orders received per day over
 * the last 14 days (platform timezone), split by what became of them:
 * delivered, cancelled, still in progress. Real database counts; when no
 * order was placed in the period the widget shows an empty state instead of
 * a flat chart.
 */
class OrdersTrendChart extends ChartWidget
{
    use RefreshesOnOrderEvents;

    public const DAYS = 14;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 2];

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    public function getHeading(): ?string
    {
        return __('admin.dashboard.trend_heading', ['days' => self::DAYS]);
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.trend_description');
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('admin.dashboard.trend_empty');
    }

    public function getEmptyStateIcon(): string|\BackedEnum|Htmlable
    {
        return 'heroicon-o-chart-bar';
    }

    /** @return array<string, array{delivered: int, cancelled: int, open: int}> local date (Y-m-d) => counts, oldest first */
    public static function series(): array
    {
        $tz = PlatformSettings::timezone();
        $start = now($tz)->startOfDay()->subDays(self::DAYS - 1);
        $days = [];
        for ($day = $start->copy(); $day->lte(now($tz)); $day->addDay()) {
            $days[$day->toDateString()] = ['delivered' => 0, 'cancelled' => 0, 'open' => 0];
        }

        Order::query()->where('created_at', '>=', $start->copy()->utc())->get(['created_at', 'status'])
            ->each(function (Order $order) use (&$days, $tz) {
                $key = Carbon::parse($order->created_at)->setTimezone($tz)->toDateString();
                if (! isset($days[$key])) {
                    return;
                }
                $bucket = match ($order->status) {
                    OrderStatus::Delivered => 'delivered',
                    OrderStatus::Cancelled => 'cancelled',
                    default => 'open',
                };
                $days[$key][$bucket]++;
            });

        return $days;
    }

    protected function getData(): array
    {
        $series = self::series();
        if (array_sum(array_map('array_sum', $series)) === 0) {
            return [];
        }

        $locale = app()->getLocale();
        $labels = array_map(fn ($date) => Carbon::parse($date)->locale($locale)->isoFormat('D MMM'), array_keys($series));
        $set = fn (string $key, string $label, string $colour) => [
            'label' => $label,
            'data' => array_values(array_column($series, $key)),
            'backgroundColor' => $colour,
            'borderWidth' => 0,
            'borderRadius' => 3,
            'maxBarThickness' => 26,
            'stack' => 'orders',
        ];

        return [
            'datasets' => [
                $set('delivered', __('admin.order_status.delivered'), '#10b981'),
                $set('open', __('admin.dashboard.in_progress'), '#6366f1'),
                $set('cancelled', __('admin.order_status.cancelled'), '#ef4444'),
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        $text = 'rgba(148, 163, 184, 0.95)';
        $rtl = app()->getLocale() === 'ar';
        $font = ['family' => "'IBM Plex Sans Arabic', system-ui, sans-serif", 'size' => 12];

        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom', 'rtl' => $rtl, 'align' => 'start',
                    'labels' => ['color' => $text, 'font' => $font, 'boxWidth' => 10, 'boxHeight' => 10, 'usePointStyle' => true, 'pointStyle' => 'rectRounded']],
                'tooltip' => ['rtl' => $rtl, 'mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'x' => ['stacked' => true, 'reverse' => $rtl, 'grid' => ['display' => false], 'border' => ['display' => false],
                    'ticks' => ['color' => $text, 'font' => $font, 'maxRotation' => 0, 'autoSkipPadding' => 12]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'position' => $rtl ? 'right' : 'left',
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'], 'border' => ['display' => false],
                    'ticks' => ['color' => $text, 'font' => $font, 'precision' => 0]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
