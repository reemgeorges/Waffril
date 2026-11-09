<?php

namespace Tests\Feature\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Jobs\Delivery\AdvanceDeliveryDispatch;
use App\Jobs\Delivery\ExpireDeliveryOffer;
use App\Jobs\Delivery\RecoverDeliveryDispatch;
use App\Models\Admin;
use App\Models\City;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Driver\DriverAccountService;
use App\Services\Driver\DriverAdminService;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Order\OrderAdminService;
use App\Support\Broadcasting\RecordingBroadcaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — automatic reassignment, fair
 * rotation, expiry jobs, the scheduled sweep, the no-driver queue and
 * auto-recovery. The queue is synchronous in tests, so follow-up jobs run
 * right after each commit (except where a lost worker is simulated).
 */
class DeliveryDispatcherTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected Admin $admin;

    protected City $cityModel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dispatch.offers_enabled' => true]);
        RecordingBroadcaster::reset();
        $this->admin = $this->superAdmin();
        $this->cityModel = $this->city();
    }

    protected function acceptedOrder(): Order
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->cityModel), '10');

        return app(OrderAdminService::class)->accept($order, $this->admin)->fresh();
    }

    protected function newDriver(array $attributes = []): Driver
    {
        return $this->driver($this->cityModel, $attributes);
    }

    protected function start(Order $order, Driver $driver): DeliveryOffer
    {
        return app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->admin);
    }

    protected function pending(Order $order): ?DeliveryOffer
    {
        return DeliveryOffer::query()->pending()->where('order_id', $order->id)->first();
    }

    protected function reject(DeliveryOffer $offer): void
    {
        app(DeliveryOfferService::class)->reject($offer, $offer->driver, 'busy');
    }

    protected function setSetting(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Filament alerts stored for the admin. */
    protected function noDriverAlerts(Admin $admin): int
    {
        return DB::table('notifications')->where('notifiable_type', Admin::class)->where('notifiable_id', $admin->id)
            ->where('data', 'like', '%order.no_driver_available%')->count();
    }

    /** The driver offered on round 1 in order: the next ones are chosen automatically. */
    protected function offeredSequence(Order $order): array
    {
        return DeliveryOffer::query()->where('order_id', $order->id)->orderBy('round')->orderBy('attempt')->pluck('driver_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_rejection_automatically_offers_the_order_to_the_next_driver(): void
    {
        $order = $this->acceptedOrder();
        [$first, $second] = [$this->newDriver(), $this->newDriver()];

        $this->reject($this->start($order, $first));

        $next = $this->pending($order);
        $this->assertNotNull($next, 'The next driver was offered automatically.');
        $this->assertSame($second->id, (int) $next->driver_id);
        $this->assertSame([1, 2, DeliveryOffer::SOURCE_AUTO], [$next->round, $next->attempt, $next->source]);
        $this->assertSame('fair_rotation', $next->selection_meta['strategy']);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        // The new driver is told live (and pushed, as in B2).
        $this->assertCount(1, RecordingBroadcaster::on("private-driver.{$second->id}", 'offer.created'));
        // History is preserved.
        $this->assertSame([$first->id, $second->id], $this->offeredSequence($order));
    }

    public function test_an_expired_offer_moves_on_when_its_delayed_job_runs(): void
    {
        Queue::fake();
        $order = $this->acceptedOrder();
        [$first, $second] = [$this->newDriver(), $this->newDriver()];
        $offer = $this->start($order, $first);

        // The expiry job is queued for the deadline (90 s by default).
        Queue::assertPushed(ExpireDeliveryOffer::class, fn ($job) => $job->offerId === $offer->id
            && abs($job->delay->getTimestamp() - $offer->expires_at->getTimestamp()) <= 1);

        // Too early: the job only reschedules itself.
        $this->assertSame('early', app()->call([new ExpireDeliveryOffer($offer->id), 'handle']));
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);

        $this->travel(90)->seconds();
        $this->assertSame('offered', app()->call([new ExpireDeliveryOffer($offer->id), 'handle']));
        $this->assertSame(DeliveryOfferStatus::Expired, $offer->fresh()->status);
        $this->assertSame($second->id, (int) $this->pending($order)->driver_id);

        // A duplicate of the same job changes nothing more.
        $this->assertSame('pending', app()->call([new ExpireDeliveryOffer($offer->id), 'handle']));
        $this->assertSame(1, DeliveryOffer::query()->pending()->where('order_id', $order->id)->count());
        $this->assertCount(2, $this->offeredSequence($order));
    }

    public function test_fair_rotation_prefers_the_longest_wait_then_fewest_deliveries_then_fresh_gps(): void
    {
        $branch = $this->branch($this->store(), $this->cityModel);
        $order = app(OrderAdminService::class)->accept($this->order($this->customer(), $branch, '10'), $this->admin)->fresh();
        $at = fn (int $minutesAgo) => now()->subMinutes($minutesAgo);

        $recentlyOffered = $this->newDriver();
        $longAgoOffered = $this->newDriver();
        $busyToday = $this->newDriver();
        // GPS columns are not mass assignable (only the location API writes them).
        $located = function (float $latOffset, int $minutesAgo) use ($branch, $at) {
            $driver = $this->newDriver();
            $driver->forceFill(['last_lat' => (float) $branch->lat + $latOffset, 'last_lng' => (float) $branch->lng, 'last_location_at' => $at($minutesAgo)])->save();

            return $driver;
        };
        $freshFar = $located(0.05, 2);
        $freshNear = $located(0.001, 1);
        $staleNearest = $located(0.0, 120);

        // Past offers on another order (history drives the rotation).
        $other = $this->acceptedOrder();
        foreach ([[$longAgoOffered, 60, 1], [$recentlyOffered, 5, 2]] as [$driver, $minutesAgo, $attempt]) {
            DB::table('delivery_offers')->insert([
                'order_id' => $other->id, 'driver_id' => $driver->id, 'round' => 1, 'attempt' => $attempt, 'status' => 'expired', 'source' => 'admin',
                'offered_by_admin_id' => $this->admin->id, 'offered_at' => $at($minutesAgo), 'expires_at' => $at($minutesAgo)->addSeconds(90),
                'responded_at' => $at($minutesAgo)->addSeconds(90), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Two deliveries completed today.
        $this->order($this->customer(), $branch, '5', ['status' => OrderStatus::Delivered, 'driver' => $busyToday]);
        $this->order($this->customer(), $branch, '5', ['status' => OrderStatus::Delivered, 'driver' => $busyToday]);

        $ranked = app(DriverRecommendationService::class)->dispatchCandidates($order, 1, 10);
        $this->assertSame(
            [$freshNear->id, $freshFar->id, $staleNearest->id, $busyToday->id, $longAgoOffered->id, $recentlyOffered->id],
            $ranked->map(fn ($row) => $row['driver']->id)->all(),
            'Never offered first; among them fewest deliveries today; then fresh GPS nearest; stale GPS is ignored.'
        );
        $byId = $ranked->keyBy(fn ($row) => $row['driver']->id);
        $this->assertTrue($byId[$freshNear->id]['meta']['location_used']);
        $this->assertFalse($byId[$staleNearest->id]['meta']['location_used'], 'A 2-hour-old position is not used.');
        $this->assertNull($byId[$staleNearest->id]['meta']['distance_km']);
        $this->assertSame(2, $byId[$busyToday->id]['meta']['deliveries_today']);
    }

    public function test_the_round_stops_at_the_attempt_cap_and_never_retries_rejecting_drivers(): void
    {
        $this->setSetting('dispatch.max_attempts_per_round', 3);
        $order = $this->acceptedOrder();
        $drivers = [$this->newDriver(), $this->newDriver(), $this->newDriver(), $this->newDriver()];

        $this->reject($this->start($order, $drivers[0]));
        $this->reject($this->pending($order));
        $this->reject($this->pending($order));

        $order->refresh();
        $this->assertNull($this->pending($order), 'No 4th offer in a 3-attempt round.');
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->dispatch_state);
        $this->assertSame(3, $order->dispatch_attempts);
        $sequence = $this->offeredSequence($order);
        $this->assertCount(3, $sequence);
        $this->assertCount(3, array_unique($sequence), 'Nobody was offered twice in the round.');
        $this->assertNotContains($drivers[3]->id, $sequence);
    }

    public function test_the_default_cap_is_ten_offers_per_round(): void
    {
        $order = $this->acceptedOrder();
        $drivers = collect(range(1, 11))->map(fn () => $this->newDriver());

        $this->reject($this->start($order, $drivers[0]));
        for ($i = 1; $i < 10; $i++) {
            $this->reject($this->pending($order));
        }

        $this->assertSame(10, DeliveryOffer::where('order_id', $order->id)->count());
        $this->assertNull($this->pending($order));
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->fresh()->dispatch_state);
    }

    public function test_a_driver_going_offline_or_deactivated_hands_the_order_to_the_next_driver(): void
    {
        $order = $this->acceptedOrder();
        [$first, $second, $third] = [$this->newDriver(), $this->newDriver(), $this->newDriver()];
        $offer = $this->start($order, $first);

        app(DriverAccountService::class)->setAvailability($first, Driver::AVAILABILITY_OFFLINE);
        $this->assertSame(DeliveryOfferStatus::Cancelled, $offer->fresh()->status);
        $this->assertSame(DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE, $offer->fresh()->cancel_reason);
        $secondOffer = $this->pending($order);
        $this->assertSame($second->id, (int) $secondOffer->driver_id);

        app(DriverAdminService::class)->setActive($second, false, $this->admin);
        $this->assertSame(DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE, $secondOffer->fresh()->cancel_reason);
        $this->assertSame($third->id, (int) $this->pending($order)->driver_id);
    }

    public function test_no_eligible_driver_queues_the_order_and_alerts_admins_once(): void
    {
        $order = $this->acceptedOrder();
        $only = $this->newDriver();
        $assigner = $this->employee(['orders.assign', 'orders.view']);
        $viewer = $this->employee(['orders.view']);

        $this->reject($this->start($order, $only));

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status, 'Never dropped or cancelled.');
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->dispatch_state);
        $this->assertNotNull($order->no_driver_since);
        $this->assertNotNull($order->no_driver_alerted_at);
        $this->assertSame(1, $this->noDriverAlerts($assigner));
        $this->assertSame(0, $this->noDriverAlerts($viewer), 'Only admins who can assign are alerted.');
        $this->assertSame(1, DeliveryOffer::where('order_id', $order->id)->count(), 'History kept.');

        // Unchanged condition: more nudges and sweeps never re-alert.
        app(DeliveryDispatcher::class)->advance($order->id);
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->assertSame(1, $this->noDriverAlerts($assigner));
    }

    public function test_recovery_offers_a_newly_available_driver_without_a_second_alert(): void
    {
        $order = $this->acceptedOrder();
        $this->reject($this->start($order, $this->newDriver()));
        $assigner = $this->admin;
        $alerts = $this->noDriverAlerts($assigner);
        $late = $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE]);

        // The driver comes online: the waiting order is offered right away (same round).
        app(DriverAccountService::class)->setAvailability($late, Driver::AVAILABILITY_AVAILABLE);

        $offer = $this->pending($order);
        $this->assertNotNull($offer);
        $this->assertSame([$late->id, 1, 'same_round'], [(int) $offer->driver_id, $offer->round, $offer->selection_meta['recovery']]);
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state);

        // If that driver also declines, the order goes back to the queue silently.
        $this->reject($offer);
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->fresh()->dispatch_state);
        $this->assertSame($alerts, $this->noDriverAlerts($assigner), 'Automatic retries do not re-alert.');
    }

    public function test_a_new_round_starts_only_after_the_cooldown_and_within_the_round_cap(): void
    {
        $this->setSetting('dispatch.max_auto_rounds', 1);
        $order = $this->acceptedOrder();
        [$a, $b] = [$this->newDriver(), $this->newDriver()];
        $this->reject($this->start($order, $a));
        $this->reject($this->pending($order)); // b declines: round 1 has nobody left
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->fresh()->dispatch_state);

        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->assertNull($this->pending($order), 'No immediate retry of drivers who just declined.');

        $this->travel(5)->minutes();
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $offer = $this->pending($order);
        $this->assertNotNull($offer, 'After the cool-down a new automatic round starts.');
        $this->assertSame([2, 1, 'new_round'], [$offer->round, $offer->attempt, $offer->selection_meta['recovery']]);

        // Both decline again; the automatic-round cap (1) is used up: an admin must act.
        $this->reject($offer);
        $this->reject($this->pending($order));
        $this->travel(10)->minutes();
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->assertNull($this->pending($order));
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $order->fresh()->dispatch_state);

        // An admin-started round resets the count (B4 restart uses this path).
        app(DeliveryOfferService::class)->offerByAdmin($order, $a, $this->admin);
        $this->assertSame(3, $order->fresh()->dispatch_round);
        $this->assertNull($order->fresh()->no_driver_alerted_at, 'Alerts re-arm after an admin acted.');
    }

    public function test_waiting_orders_are_served_oldest_first(): void
    {
        $older = $this->acceptedOrder();
        $newer = $this->acceptedOrder();
        // Each order's only driver declines and then goes off duty.
        foreach ([$older, $newer] as $order) {
            $driver = $this->newDriver();
            $this->reject($this->start($order, $driver));
            $driver->forceFill(['availability' => Driver::AVAILABILITY_OFFLINE])->save();
            $this->travel(1)->minutes();
        }
        $this->assertSame([Order::DISPATCH_NO_DRIVER, Order::DISPATCH_NO_DRIVER], [$older->fresh()->dispatch_state, $newer->fresh()->dispatch_state]);

        $free = $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE]);
        app(DriverAccountService::class)->setAvailability($free, Driver::AVAILABILITY_AVAILABLE);

        $this->assertSame($free->id, (int) $this->pending($older)->driver_id, 'The order waiting longest gets the free driver.');
        $this->assertNull($this->pending($newer), 'One driver, one pending offer.');
    }

    public function test_the_sweep_recovers_lost_jobs_after_a_worker_restart(): void
    {
        Queue::fake(); // nothing queued ever runs: a dead worker
        $order = $this->acceptedOrder();
        [$first, $second, $third] = [$this->newDriver(), $this->newDriver(), $this->newDriver()];
        $offer = $this->start($order, $first);

        $this->travel(91)->seconds();
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->assertSame(DeliveryOfferStatus::Expired, $offer->fresh()->status);
        $secondOffer = $this->pending($order);
        $this->assertSame($second->id, (int) $secondOffer->driver_id);

        // A rejection whose follow-up job was lost leaves the order "offering" without a pending offer.
        $this->reject($secondOffer);
        Queue::assertPushed(AdvanceDeliveryDispatch::class);
        $this->assertNull($this->pending($order));
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $this->assertSame($third->id, (int) $this->pending($order)->driver_id);
    }

    public function test_acceptance_ends_the_dispatch_and_late_jobs_do_nothing(): void
    {
        $order = $this->acceptedOrder();
        [$first, $second] = [$this->newDriver(), $this->newDriver()];
        $offer = $this->start($order, $first);
        app(DeliveryOfferService::class)->accept($offer, $first);

        $this->travel(91)->seconds();
        $this->assertSame('not_dispatching', app()->call([new ExpireDeliveryOffer($offer->id), 'handle']));
        $this->assertSame('not_dispatching', app()->call([new AdvanceDeliveryDispatch($order->id), 'handle']));
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();

        $this->assertSame(DeliveryOfferStatus::Accepted, $offer->fresh()->status);
        $this->assertSame([OrderStatus::Assigned, $first->id], [$order->fresh()->status, (int) $order->fresh()->driver_id]);
        $this->assertSame(1, DeliveryOffer::where('order_id', $order->id)->count());
        $this->assertSame([], RecordingBroadcaster::on("private-driver.{$second->id}"));
    }

    public function test_cancelling_the_order_stops_the_dispatch(): void
    {
        $order = $this->acceptedOrder();
        [$first, $second] = [$this->newDriver(), $this->newDriver()];
        $offer = $this->start($order, $first);

        app(OrderAdminService::class)->cancelByAdmin($order, $this->admin, ['reason_code' => 'other', 'note' => 'test']);
        $this->travel(91)->seconds();
        app()->call([new ExpireDeliveryOffer($offer->id), 'handle']);
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();

        $this->assertSame(DeliveryOffer::CANCEL_ORDER_CANCELLED, $offer->fresh()->cancel_reason);
        $this->assertSame(1, DeliveryOffer::where('order_id', $order->id)->count(), 'No offer for a cancelled order.');
        $this->assertSame([], RecordingBroadcaster::on("private-driver.{$second->id}"));
    }

    public function test_everything_stays_inert_while_offers_are_disabled(): void
    {
        config(['dispatch.offers_enabled' => false]);
        Bus::fake([AdvanceDeliveryDispatch::class, ExpireDeliveryOffer::class, RecoverDeliveryDispatch::class]);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE]);

        $this->artisan('waffrli:dispatch-sweep')->expectsOutputToContain('disabled')->assertSuccessful();
        $this->assertSame('disabled', app(DeliveryDispatcher::class)->advance($order->id));
        $this->assertSame(['resumed' => 0, 'new_round' => 0, 'waiting' => 0], app(DeliveryDispatcher::class)->recover());
        app(DriverAccountService::class)->setAvailability($driver, Driver::AVAILABILITY_AVAILABLE);
        app(DriverAccountService::class)->setAvailability($driver, Driver::AVAILABILITY_OFFLINE);

        Bus::assertNotDispatched(AdvanceDeliveryDispatch::class);
        Bus::assertNotDispatched(ExpireDeliveryOffer::class);
        Bus::assertNotDispatched(RecoverDeliveryDispatch::class);
        $this->assertSame(0, DeliveryOffer::count());
        $this->assertNull($order->fresh()->dispatch_state);
        // The existing direct assignment still works as before.
        app(OrderAdminService::class)->assignDriver($order, $this->newDriver(), $this->admin);
        $this->assertSame(OrderStatus::Assigned, $order->fresh()->status);
    }
}
