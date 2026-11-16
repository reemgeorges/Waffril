<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Widgets\OrdersTrendChart;
use App\Filament\Widgets\TodayOverview;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-16, Phase 5/14) — the dashboard trend figures are now
 * aggregated by the database (App\Support\Database\DayBuckets). They must
 * be identical to the previous row-by-row PHP calculation, in UTC and in
 * non-UTC platform timezones (including a DST change), on SQLite and MySQL.
 */
class DashboardAggregatesTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public static function timezones(): array
    {
        return ['utc' => ['UTC', '2026-11-16 09:30:00'], 'damascus' => ['Asia/Damascus', '2026-11-16 22:10:00'], 'dst' => ['Europe/Berlin', '2026-04-05 01:30:00']];
    }

    #[DataProvider('timezones')]
    public function test_sql_aggregates_equal_the_previous_php_calculation(string $timezone, string $nowUtc): void
    {
        Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));
        Setting::query()->updateOrCreate(['key' => 'operations.timezone'], ['value' => $timezone]);
        $this->assertSame($timezone, PlatformSettings::timezone());

        $branch = $this->branch($this->store(), $this->city());
        $customer = $this->customer();
        mt_srand(20261116);
        $statuses = [OrderStatus::Delivered, OrderStatus::Cancelled, OrderStatus::Pending, OrderStatus::Accepted];
        for ($i = 0; $i < 60; $i++) {
            $order = $this->order($customer, $branch, (string) (5 + $i % 7));
            // Spread over ~16 days around day boundaries, plus exact-midnight edge cases.
            $created = now()->subMinutes(mt_rand(0, 16 * 24 * 60));
            if ($i % 10 === 0) {
                $created = now($timezone)->startOfDay()->subDays($i % 5)->utc();
            }
            $status = $statuses[$i % 4];
            $delivered = $status === OrderStatus::Delivered ? $created->copy()->addSeconds(mt_rand(300, 6000)) : null;
            DB::table('orders')->where('id', $order->id)->update([
                'status' => $status->value, 'created_at' => $created, 'delivered_at' => $delivered,
            ]);
            if ($status === OrderStatus::Cancelled) {
                DB::table('order_status_histories')->insert([
                    'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'cancelled', 'actor_type' => 'system',
                    'created_at' => $created->copy()->addMinutes(mt_rand(1, 900)),
                ]);
            }
        }

        $series = TodayOverview::series();
        $this->assertGreaterThan(30, array_sum($series['received']), 'The window must actually hold data.');
        $this->assertGreaterThan(5, array_sum($series['cancelled']));
        $this->assertNotEmpty(array_filter($series['minutes']));
        $this->assertSame($this->legacyTodaySeries($timezone), $series);
        $this->assertSame($this->legacyTrendSeries($timezone), OrdersTrendChart::series());

        Carbon::setTestNow();
    }

    /** The implementation before 2026-11-16 (TodayOverview::series), verbatim in behaviour. */
    private function legacyTodaySeries(string $tz, int $days = 14): array
    {
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

    /** The implementation before 2026-11-16 (OrdersTrendChart::series). */
    private function legacyTrendSeries(string $tz): array
    {
        $start = now($tz)->startOfDay()->subDays(OrdersTrendChart::DAYS - 1);
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
                $days[$key][match ($order->status) {
                    OrderStatus::Delivered => 'delivered',
                    OrderStatus::Cancelled => 'cancelled',
                    default => 'open',
                }]++;
            });

        return $days;
    }
}
