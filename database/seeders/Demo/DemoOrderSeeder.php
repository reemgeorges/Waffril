<?php

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Driver;
use App\Models\DriverCashSettlement;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderPayment;
use App\Services\Driver\DriverCashSettlementService;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI demo — ~35 orders across every status, built by DemoOrderBuilder
 * and moved ONLY through the real services (accept, assign, pick up,
 * deliver, cancel, cancellation requests, refunds, settlements), each step
 * back-dated with time travel. Wallet money only ever comes from refunds
 * and cancellation reversals.
 *
 * Every scenario runs in one transaction and is skipped when its order
 * number already exists, so re-running never duplicates anything.
 */
class DemoOrderSeeder extends Seeder
{
    protected DemoContext $context;

    protected OrderAdminService $orders;

    /**
     * number => [when, customer, store slug, branch key, lines, options, steps]
     * when: [dayOffset, hour, minute] in the platform timezone, or int minutes relative to now.
     * steps: [minutes after submission, action, argument]
     */
    public static function scenarios(): array
    {
        $grill = 'demo-shami-grill';
        $pizza = 'demo-pizza-roma';
        $market = 'demo-fresh-market';
        $pharmacy = 'demo-shifa-pharmacy';
        $bakery = 'demo-golden-bakery';
        $tech = 'demo-tech-corner';
        $delivered = fn (int $driver, int $start = 5) => [[$start, 'accept'], [$start + 5, 'assign', $driver], [$start + 20, 'pickup'], [$start + 40, 'deliver']];

        return [
            // ---- Delivered history (last two weeks) ----
            'DEMO-0001' => [[-13, 13, 0], 1, $grill, 'mazzeh', [['shawarma-wrap', 2, ['saj', 'garlic-sauce']], ['ayran', 2, []]], [], $delivered(1)],
            'DEMO-0002' => [[-12, 13, 30], 2, $pizza, 'mazzeh-west', [['margherita', 1, ['large', 'extra-cheese']], ['cola', 2, []]], [], $delivered(2)],
            'DEMO-0003' => [[-11, 12, 0], 3, $market, 'center', [['milk', 2, []], ['labneh', 1, []], ['olive-oil', 1, []], ['water-6', 1, []]], [],
                [...$delivered(3), [90, 'refund', ['5.00', 'damaged_item', 'Demo: olive-oil bottle arrived broken']]]],
            'DEMO-0004' => [[-10, 13, 0], 4, $pharmacy, 'mazzeh', [['paracetamol', 2, []], ['vitamin-c', 1, []]], [], $delivered(4)],
            'DEMO-0005' => [[-10, 14, 0], 9, $grill, 'aleppo', [['kebab-plate', 2, ['garlic-sauce']]], ['currency' => 'SYP'],
                [...$delivered(7), [80, 'refund', ['20000', 'missing_item', 'Demo: garlic sauce missing']]]],
            'DEMO-0006' => [[-9, 13, 0], 5, $bakery, 'salhiyeh', [['manakish', 4, []], ['croissant', 2, []]], [], $delivered(5)],
            'DEMO-0007' => [[-8, 13, 0], 6, $tech, 'center', [['power-bank', 1, []]], [], $delivered(6)],
            'DEMO-0008' => [[-7, 13, 0], 1, $grill, 'old-city', [['mixed-grill', 1, ['large']], ['ayran', 1, []]], ['free_delivery' => true], $delivered(1)],
            'DEMO-0009' => [[-6, 13, 0], 3, $market, 'north', [['water-6', 2, []], ['chips', 3, []]], ['wallet' => '4.00'], $delivered(2)],
            'DEMO-0010' => [[-5, 13, 0], 7, $pizza, 'mazzeh-west', [['pepperoni', 2, ['medium']]], [],
                [[4, 'accept'], [6, 'request_cancel', 'Can I cancel? I ordered twice.'], [9, 'reject_request', 'Demo: already in the oven'], [10, 'assign', 3], [25, 'pickup'], [45, 'deliver']]],
            'DEMO-0011' => [[-4, 13, 0], 2, $grill, 'mazzeh', [['shawarma-wrap', 3, ['tannour']]], ['free_delivery' => true], $delivered(4)],
            'DEMO-0012' => [[-3, 13, 0], 10, $pharmacy, 'homs', [['hand-sanitizer', 2, []], ['paracetamol', 1, []]], [],
                [[20, 'cancel_admin', ['reason_code' => 'branch_unavailable', 'note' => 'Demo: no driver available in Homs']]]],
            'DEMO-0013' => [[-2, 13, 0], 9, $pizza, 'aleppo-center', [['margherita', 2, ['small']]], ['currency' => 'SYP', 'wallet' => '15000'], $delivered(8)],
            'DEMO-0014' => [[-2, 13, 30], 5, $tech, 'center', [['usb-c-cable', 2, []]], ['currency' => 'EUR'],
                [...$delivered(5), [70, 'refund', ['3.00', 'goodwill', 'Demo: late delivery goodwill refund']]]],
            'DEMO-0015' => [[-2, 12, 0], 4, $market, 'center', [['labneh', 2, []], ['milk', 1, []]], [], $delivered(6)],

            // ---- Cancelled ----
            'DEMO-0016' => [[-4, 18, 0], 7, $market, 'north', [['chips', 3, []], ['water-6', 1, []]], [], [[3, 'cancel_customer', 'Demo: ordered by mistake']]],
            'DEMO-0017' => [[-3, 19, 0], 3, $grill, 'old-city', [['kebab-plate', 1, []]], ['wallet' => '1.00'],
                [[5, 'accept'], [15, 'cancel_admin', ['reason_code' => 'product_unavailable', 'note' => 'Demo: grill out of service']]]],
            'DEMO-0018' => [[-2, 20, 0], 4, $grill, 'mazzeh', [['shawarma-wrap', 2, ['saj']]], [],
                [[5, 'accept'], [8, 'assign', 2], [20, 'pickup'], [35, 'cancel_admin', ['reason_code' => 'customer_refused', 'note' => 'Demo: customer refused at the door', 'driver_handling' => 'returned_to_branch', 'cash_collected_amount' => '0']]]],
            'DEMO-0019' => [[-1, 18, 0], 2, $pizza, 'mazzeh-west', [['garlic-bread', 2, []], ['cola', 1, []]], ['free_delivery' => true],
                [[5, 'accept'], [9, 'request_cancel', 'Plans changed, sorry!'], [12, 'approve_request', ['reason_code' => 'customer_request', 'note' => 'Demo: approved before preparation']]]],

            // ---- Today: delivered (cash to settle) ----
            'DEMO-0020' => [-200, 1, $market, 'center', [['chips', 3, []], ['water-6', 1, []]], [],
                [...$delivered(5, 3), [70, 'refund', ['2.00', 'missing_item', 'Demo: one bag of chips missing']]]],
            'DEMO-0021' => [-140, 6, $grill, 'mazzeh', [['kebab-plate', 1, []]], [], $delivered(5, 3)],
            'DEMO-0022' => [-150, 2, $pizza, 'mazzeh-west', [['garlic-bread', 1, []]], [], $delivered(6, 3)],

            // ---- Live: pending, scheduled, urgent ----
            'DEMO-0023' => [-6, 5, $grill, 'mazzeh', [['shawarma-wrap', 1, ['saj', 'fries']], ['ayran', 1, []]], [], []],
            'DEMO-0024' => [-4, 6, $market, 'center', [['milk', 2, []], ['labneh', 1, []]], ['currency' => 'SYP'], []],
            'DEMO-0025' => [-3, 1, $grill, 'old-city', [['mixed-grill', 1, ['medium']]], ['free_delivery' => true], []],
            'DEMO-0026' => [-30, 3, $market, 'center', [['olive-oil', 1, []]], ['wallet' => '1.00', 'scheduled_for' => 180], []],
            'DEMO-0027' => [-90, 7, $bakery, 'salhiyeh', [['maamoul', 2, []]], ['scheduled_for' => [1, 9, 0]], []],
            'DEMO-0028' => [-1440, 4, $pharmacy, 'mazzeh', [['vitamin-c', 1, []]], ['scheduled_for' => -20], [[1440, 'flag_urgent']]],

            // ---- Live: accepted / assigned / picked up (driver map) ----
            'DEMO-0029' => [-15, 2, $grill, 'old-city', [['kebab-plate', 1, []], ['ayran', 2, []]], [], [[3, 'accept'], [10, 'request_cancel', 'Is it possible to cancel?']]],
            'DEMO-0030' => [-60, 6, $pizza, 'mazzeh-west', [['pepperoni', 1, ['large']]], ['scheduled_for' => 40], [[55, 'accept']]],
            'DEMO-0031' => [-20, 7, $grill, 'mazzeh', [['shawarma-wrap', 2, ['tannour', 'extra-cheese']]], [], [[3, 'accept'], [8, 'assign', 1]]],
            'DEMO-0032' => [-25, 1, $market, 'center', [['labneh', 2, []], ['milk', 2, []]], ['free_delivery' => true], [[3, 'accept'], [10, 'assign', 2]]],
            'DEMO-0033' => [-35, 3, $pizza, 'mazzeh-west', [['margherita', 1, ['medium']]], [], [[3, 'accept'], [8, 'assign', 3], [25, 'pickup']]],
            'DEMO-0034' => [-50, 4, $pharmacy, 'mazzeh', [['paracetamol', 1, []], ['hand-sanitizer', 1, []]], [], [[3, 'accept'], [8, 'assign', 4], [20, 'pickup']]],
            'DEMO-0035' => [-15, 9, $grill, 'aleppo', [['shawarma-wrap', 2, ['saj']]], ['currency' => 'SYP'], [[3, 'accept'], [7, 'assign', 7]]],
        ];
    }

    /** Static DEMO positions (not GPS): [lat, lng, minutes since the last update]. */
    public const DRIVER_POSITIONS = [
        1 => [33.5038, 36.2478, 1],   // heading to Shami Grill, Mazzeh
        2 => [33.5142, 36.2932, 2],   // at Fresh Market, Shaalan
        3 => [33.5065, 36.2470, 1],   // carrying a pizza towards Shaalan
        4 => [33.5131, 36.2795, 27],  // stale position: last update 27 minutes ago
        5 => [33.5390, 36.2840, 4],   // available, no active delivery
        6 => [33.5210, 36.3010, 180], // offline
        7 => [36.2068, 37.1385, 3],   // Aleppo, heading to Shami Grill Aleppo
        8 => [36.1990, 37.1290, 9],   // Aleppo, available
    ];

    public function run(): void
    {
        $this->context = app(DemoContext::class);
        $this->orders = app(OrderAdminService::class);
        $builder = app(DemoOrderBuilder::class);

        foreach (self::scenarios() as $number => [$when, $customer, $storeSlug, $branchKey, $lines, $options, $steps]) {
            if (Order::query()->where('order_number', $number)->exists()) {
                continue;
            }

            $submittedAt = $this->moment($when);
            if (isset($options['scheduled_for'])) {
                $options['scheduled_for'] = $this->moment($options['scheduled_for']);
            }

            DB::transaction(function () use ($builder, $number, $submittedAt, $customer, $storeSlug, $branchKey, $lines, $options, $steps) {
                $order = $this->context->at($submittedAt, fn () => $builder->submit(
                    $number, DemoPeopleSeeder::customer($customer), DemoCatalogSeeder::branchFor($storeSlug, $branchKey), $storeSlug, $lines, $options
                ));

                foreach ($steps as $step) {
                    [$minutes, $action] = $step;
                    $this->context->at($submittedAt->copy()->addMinutes($minutes), fn () => $this->step($order->fresh(), $action, $step[2] ?? null));
                }
            });
        }

        $this->settlements();
        $this->placeDrivers();
    }

    protected function step(Order $order, string $action, mixed $argument): void
    {
        $admin = $this->context->operator();

        match ($action) {
            'accept' => $this->orders->accept($order, $admin),
            'assign' => $this->orders->assignDriver($order, DemoPeopleSeeder::driver($argument), $admin),
            'pickup' => $this->orders->markPickedUp($order, Driver::findOrFail($order->driver_id)),
            'deliver' => $this->orders->markDelivered($order, Driver::findOrFail($order->driver_id)),
            'cancel_customer' => $this->orders->cancelByCustomer($order, $argument, 'demo:'.$order->order_number.':cancel'),
            'cancel_admin' => $this->orders->cancelByAdmin($order, $admin, $argument + ['idempotency_key' => 'demo:'.$order->order_number.':cancel']),
            'request_cancel' => OrderCancellationRequest::create(['order_id' => $order->id, 'customer_note' => $argument]),
            'reject_request' => $this->orders->rejectCancellationRequest($this->pendingRequest($order), $admin, $argument),
            'approve_request' => $this->orders->approveCancellationRequest($this->pendingRequest($order), $admin, $argument + ['idempotency_key' => 'demo:'.$order->order_number.':cancel']),
            'refund' => app(RefundService::class)->refund(
                OrderPayment::query()->where('order_id', $order->id)->whereHas('method', fn ($query) => $query->where('kind', 'cash'))->firstOrFail(),
                $argument[0], $argument[1], $argument[2], $admin, 'demo:'.$order->order_number.':refund'
            ),
            'flag_urgent' => $this->orders->flagLateScheduledOrders(),
        };
    }

    protected function pendingRequest(Order $order): OrderCancellationRequest
    {
        return OrderCancellationRequest::query()->where('order_id', $order->id)->where('status', OrderCancellationRequest::STATUS_PENDING)->firstOrFail();
    }

    /** Daily cash settlements: one settled exactly, one disputed, one still open; older cash stays unsettled. */
    protected function settlements(): void
    {
        $admin = $this->context->operator();
        $service = app(DriverCashSettlementService::class);
        $usd = Currency::query()->where('code', 'USD')->first();
        if (! $usd) {
            return;
        }

        $plan = [
            // [driver, order whose delivery date is the business date, received (null = leave open), note]
            [5, 'DEMO-0020', 'exact', null],
            [6, 'DEMO-0015', '2.00', 'Demo: driver handed in less cash; under review'],
            [6, 'DEMO-0022', null, null],
        ];

        foreach ($plan as [$driverN, $number, $received, $note]) {
            $deliveredAt = Order::query()->where('order_number', $number)->value('delivered_at');
            if (! $deliveredAt) {
                continue;
            }
            $date = Carbon::parse($deliveredAt)->setTimezone(PlatformSettings::timezone())->toDateString();
            $driver = DemoPeopleSeeder::driver($driverN);

            $existing = DriverCashSettlement::query()->where('driver_id', $driver->id)->where('currency_id', $usd->id)->whereDate('business_date', $date)->first();
            if ($existing) {
                continue;
            }

            $this->context->at(Carbon::parse($deliveredAt)->addHours(1), function () use ($service, $driver, $usd, $date, $admin, $received, $note) {
                $settlement = $service->prepare($driver, $usd, $date, $admin);
                if ($received !== null && $settlement->expected_amount > 0) {
                    $service->settle($settlement, $received === 'exact' ? (string) $settlement->expected_amount : $received, $note, $admin);
                }
            });
        }
    }

    /** Static demo positions and availability, so the driver map has markers immediately. */
    protected function placeDrivers(): void
    {
        foreach (self::DRIVER_POSITIONS as $n => [$lat, $lng, $minutesAgo]) {
            DemoPeopleSeeder::driver($n)->forceFill([
                'last_lat' => $lat, 'last_lng' => $lng,
                'last_location_at' => $this->context->anchor()->subMinutes($minutesAgo),
                'availability' => $n === 6 ? Driver::AVAILABILITY_OFFLINE : Driver::AVAILABILITY_AVAILABLE,
            ])->save();
        }
    }

    /** [day, hour, minute] in the platform timezone, or minutes relative to the anchor. */
    protected function moment(array|int $when): Carbon
    {
        if (is_int($when)) {
            return $this->context->anchor()->addMinutes($when);
        }

        [$day, $hour, $minute] = $when;

        return $this->context->anchor()->setTimezone(PlatformSettings::timezone())
            ->addDays($day)->setTime($hour, $minute)->setTimezone(config('app.timezone'));
    }
}
