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
use Illuminate\Support\Carbon;

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
            'series' => self::series(),
            'received' => Order::query()->where('created_at', '>=', $start)->count(),
            'delivered' => $delivered->count(),
            'cancelled' => OrderStatusHistory::query()->where('to_status', OrderStatus::Cancelled->value)->where('created_at', '>=', $start)->count(),
            'avg_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
            'sales' => $sales,
        ];
    }

    /**
     * The last 14 days (platform timezone), oldest first: orders received,
     * delivered, cancelled and the average delivery time per day. Real
     * database values, drawn as sparklines on the KPI cards.
     *
     * @return array{received: list<int>, delivered: list<int>, cancelled: list<int>, minutes: list<?int>}
     */
    public static function series(int $days = 14): array
    {
        $tz = PlatformSettings::timezone();
        $start = now($tz)->startOfDay()->subDays($days - 1);
        $keys = [];
        for ($day = $start->copy(); count($keys) < $days; $day->addDay()) {
            $keys[$day->toDateString()] = 0;
        }
        $bucket = fn ($at) => $at ? Carbon::parse($at)->setTimezone($tz)->toDateString() : null;
        $from = $start->copy()->utc();

        $received = $keys;
        foreach (Order::query()->where('created_at', '>=', $from)->pluck('created_at') as $at) {
            if (isset($received[$key = $bucket($at)])) {
                $received[$key]++;
            }
        }

        $delivered = $keys;
        $minutes = array_map(fn () => [], $keys);
        foreach (Order::query()->where('status', OrderStatus::Delivered->value)->where('delivered_at', '>=', $from)->get(['created_at', 'delivered_at']) as $order) {
            if (isset($delivered[$key = $bucket($order->delivered_at)])) {
                $delivered[$key]++;
                $minutes[$key][] = $order->created_at->diffInMinutes($order->delivered_at);
            }
        }

        $cancelled = $keys;
        foreach (OrderStatusHistory::query()->where('to_status', OrderStatus::Cancelled->value)->where('created_at', '>=', $from)->pluck('created_at') as $at) {
            if (isset($cancelled[$key = $bucket($at)])) {
                $cancelled[$key]++;
            }
        }

        return [
            'received' => array_values($received),
            'delivered' => array_values($delivered),
            'cancelled' => array_values($cancelled),
            'minutes' => array_values(array_map(fn ($list) => $list === [] ? null : (int) round(array_sum($list) / count($list)), $minutes)),
        ];
    }

    /**
     * SVG polyline and area for a sparkline in a 100×32 box (gaps for days
     * without a value are drawn at the previous value).
     *
     * @param  list<?int>  $values
     * @return array{line: string, area: string}
     */
    public static function sparkline(array $values): array
    {
        $filled = [];
        $last = 0;
        foreach ($values as $value) {
            $filled[] = $last = $value ?? $last;
        }
        $max = max(1, ...$filled);
        $step = count($filled) > 1 ? 100 / (count($filled) - 1) : 100;
        $points = [];
        foreach ($filled as $i => $value) {
            $points[] = round($i * $step, 2).','.round(30 - ($value / $max) * 26, 2);
        }
        $line = implode(' ', $points);

        return ['line' => $line, 'area' => '0,32 '.$line.' 100,32'];
    }

    /** Today compared with yesterday, for the KPI footnote. */
    public static function delta(array $values): ?int
    {
        $count = count($values);

        return $count >= 2 && $values[$count - 1] !== null && $values[$count - 2] !== null
            ? (int) $values[$count - 1] - (int) $values[$count - 2] : null;
    }

    protected function getViewData(): array
    {
        $f = self::figures();
        $cards = [
            ['key' => 'received', 'label' => __('admin.dashboard.received_today'), 'value' => $f['received'], 'tone' => 'info', 'icon' => 'heroicon-o-inbox-stack', 'series' => $f['series']['received'], 'up_is_good' => true],
            ['key' => 'delivered', 'label' => __('admin.dashboard.delivered_today'), 'value' => $f['delivered'], 'tone' => 'success', 'icon' => 'heroicon-o-check-badge', 'series' => $f['series']['delivered'], 'up_is_good' => true],
            ['key' => 'cancelled', 'label' => __('admin.dashboard.cancelled_today'), 'value' => $f['cancelled'], 'tone' => 'danger', 'icon' => 'heroicon-o-x-circle', 'series' => $f['series']['cancelled'], 'up_is_good' => false],
            ['key' => 'minutes', 'label' => __('admin.dashboard.avg_delivery_time'), 'value' => $f['avg_minutes'], 'unit' => __('admin.dashboard.minutes'), 'tone' => 'transit', 'icon' => 'heroicon-o-clock', 'series' => $f['series']['minutes'], 'up_is_good' => false],
        ];

        return ['f' => $f, 'cards' => array_map(fn (array $card) => $card + [
            'spark' => self::sparkline($card['series']),
            'delta' => self::delta($card['series']),
            'has_data' => array_filter($card['series'], fn ($v) => (int) $v > 0) !== [],
        ], $cards)];
    }
}
