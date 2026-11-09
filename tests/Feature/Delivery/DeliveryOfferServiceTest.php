<?php

namespace Tests\Feature\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\City;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Order\OrderAdminService;
use Illuminate\Database\QueryException;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — delivery-offer domain invariants.
 * Runs on SQLite by default and on MySQL with the isolated test database
 * (DB_CONNECTION=mysql DB_DATABASE=waffrli_test).
 */
class DeliveryOfferServiceTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected Admin $admin;

    protected City $cityModel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dispatch.offers_enabled' => true]);
        $this->admin = $this->superAdmin();
        $this->cityModel = $this->city();
    }

    protected function service(): DeliveryOfferService
    {
        return app(DeliveryOfferService::class);
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

    protected function expectRule(int $status, callable $call): BusinessRuleException
    {
        try {
            $call();
        } catch (BusinessRuleException $e) {
            $this->assertSame($status, $e->getStatus(), $e->getMessage());

            return $e;
        }
        $this->fail("Expected a BusinessRuleException with status {$status}.");
    }

    protected function setSetting(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function test_offers_are_disabled_by_default_and_direct_assignment_is_unchanged(): void
    {
        config(['dispatch.offers_enabled' => false]);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();

        $this->expectRule(409, fn () => $this->service()->offerByAdmin($order, $driver, $this->admin));
        $this->assertSame(0, DeliveryOffer::count());

        // The existing admin flow still assigns immediately, as before.
        $assigned = app(OrderAdminService::class)->assignDriver($order, $driver, $this->admin);
        $this->assertSame(OrderStatus::Assigned, $assigned->status);
        $this->assertSame($driver->id, (int) $assigned->driver_id);
        $this->assertNull($assigned->dispatch_state);
        $this->assertSame(0, $assigned->dispatch_round);
        $this->assertNull($assigned->assigned_via_offer_id);
    }

    public function test_an_admin_offer_keeps_the_order_accepted_with_a_server_deadline(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $historyBefore = OrderStatusHistory::where('order_id', $order->id)->count();

        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->assertSame(DeliveryOfferStatus::Pending, $offer->status);
        $this->assertSame([1, 1], [$offer->round, $offer->attempt]);
        $this->assertSame(DeliveryOffer::SOURCE_ADMIN, $offer->source);
        $this->assertSame(90, (int) $offer->offered_at->diffInSeconds($offer->expires_at), 'Default timeout is 90 s.');

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status, 'Stays accepted while the offer is out.');
        $this->assertNull($order->driver_id);
        $this->assertSame(Order::DISPATCH_OFFERING, $order->dispatch_state);
        $this->assertSame([1, 1], [$order->dispatch_round, $order->dispatch_attempts]);
        $this->assertSame($historyBefore, OrderStatusHistory::where('order_id', $order->id)->count(), 'No status change yet.');

        // The customer still sees an accepted order without a driver.
        $customerView = app(CustomerOrderService::class)->present($order->fresh());
        $this->assertSame('accepted', $customerView['status']);
        $this->assertNull($customerView['driver'] ?? null);
        $this->assertArrayNotHasKey('dispatch_state', $customerView);
    }

    public function test_acceptance_assigns_through_the_state_machine_and_is_idempotent(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $assigned = $this->service()->accept($offer, $driver);
        $this->assertSame(OrderStatus::Assigned, $assigned->status);
        $this->assertSame($driver->id, (int) $assigned->driver_id);
        $this->assertSame($offer->id, (int) $assigned->assigned_via_offer_id);
        $this->assertSame($this->admin->id, (int) $assigned->assigned_by_admin_id, 'Credited to the admin who started the round.');
        $this->assertNull($assigned->dispatch_state);
        $this->assertSame(DeliveryOfferStatus::Accepted, $offer->fresh()->status);
        $this->assertNotNull($offer->fresh()->responded_at);

        $history = OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'assigned')->get();
        $this->assertCount(1, $history);
        $this->assertSame('driver', $history->first()->actor_type);

        // Retrying the same accept (lost response, double tap) changes nothing.
        $again = $this->service()->accept($offer->fresh(), $driver);
        $this->assertSame($assigned->id, $again->id);
        $this->assertCount(1, OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'assigned')->get());
    }

    public function test_only_the_offered_driver_can_answer(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $other = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->expectRule(404, fn () => $this->service()->accept($offer, $other));
        $this->expectRule(404, fn () => $this->service()->reject($offer, $other, 'busy'));
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_one_pending_offer_per_order_in_the_service_and_the_database(): void
    {
        $order = $this->acceptedOrder();
        $first = $this->newDriver();
        $second = $this->newDriver();
        $this->service()->offerByAdmin($order, $first, $this->admin);

        $this->expectRule(409, fn () => $this->service()->offerByAdmin($order, $second, $this->admin));

        // Even a write that bypasses the service is refused by the database.
        $this->expectException(QueryException::class);
        DeliveryOffer::create([
            'order_id' => $order->id, 'driver_id' => $second->id, 'round' => 1, 'attempt' => 2,
            'status' => DeliveryOfferStatus::Pending, 'source' => DeliveryOffer::SOURCE_AUTO,
            'offered_at' => now(), 'expires_at' => now()->addSeconds(90),
        ]);
    }

    public function test_one_pending_offer_per_driver(): void
    {
        $driver = $this->newDriver();
        $orderA = $this->acceptedOrder();
        $orderB = $this->acceptedOrder();
        $this->service()->offerByAdmin($orderA, $driver, $this->admin);

        $this->expectRule(409, fn () => $this->service()->offerByAdmin($orderB, $driver, $this->admin));

        $this->expectException(QueryException::class);
        DeliveryOffer::create([
            'order_id' => $orderB->id, 'driver_id' => $driver->id, 'round' => 1, 'attempt' => 1,
            'status' => DeliveryOfferStatus::Pending, 'source' => DeliveryOffer::SOURCE_AUTO,
            'offered_at' => now(), 'expires_at' => now()->addSeconds(90),
        ]);
    }

    public function test_a_rejecting_driver_is_not_offered_the_same_order_again_in_the_round(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $next = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $rejected = $this->service()->reject($offer, $driver, 'too_far', 'across the city');
        $this->assertSame(DeliveryOfferStatus::Rejected, $rejected->status);
        $this->assertSame('too_far', $rejected->reject_reason);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state);
        // Rejecting twice is harmless.
        $this->assertSame(DeliveryOfferStatus::Rejected, $this->service()->reject($offer->fresh(), $driver, 'too_far')->status);

        $this->expectRule(422, fn () => $this->service()->offerAutomatically($order, $driver));
        $auto = $this->service()->offerAutomatically($order, $next, ['strategy' => 'fair_rotation']);
        $this->assertSame([1, 2], [$auto->round, $auto->attempt]);
        $this->assertSame(['strategy' => 'fair_rotation'], $auto->selection_meta);

        // The database refuses a second offer to the same driver in the round.
        $this->service()->reject($auto, $next, 'busy');
        try {
            DeliveryOffer::create([
                'order_id' => $order->id, 'driver_id' => $driver->id, 'round' => 1, 'attempt' => 3,
                'status' => DeliveryOfferStatus::Pending, 'source' => DeliveryOffer::SOURCE_AUTO,
                'offered_at' => now(), 'expires_at' => now()->addSeconds(90),
            ]);
            $this->fail('uq_do_order_round_driver must refuse it.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // A new round started by the admin may offer the same driver again.
        $restart = $this->service()->offerByAdmin($order, $driver, $this->admin);
        $this->assertSame([2, 1], [$restart->round, $restart->attempt]);
    }

    public function test_invalid_reject_reasons_are_refused(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->expectRule(422, fn () => $this->service()->reject($offer, $driver, 'free_text'));
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);
    }

    public function test_expired_offers_can_no_longer_be_accepted_or_rejected(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->assertFalse($this->service()->expire($offer), 'Not expired before the deadline.');
        $this->travel(89)->seconds();
        $this->assertFalse($this->service()->expire($offer));

        $this->travel(1)->seconds(); // exactly at the deadline
        $this->expectRule(409, fn () => $this->service()->accept($offer, $driver));
        $this->assertSame(DeliveryOfferStatus::Expired, $offer->fresh()->status, 'The late accept records the expiry.');
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);

        $this->expectRule(409, fn () => $this->service()->reject($offer->fresh(), $driver, 'busy'));
        $this->assertFalse($this->service()->expire($offer->fresh()), 'Expiring twice is a no-op.');
    }

    public function test_the_expiry_sweep_marks_overdue_offers_once(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->travel(120)->seconds();
        $this->assertTrue($this->service()->expire($offer));
        $this->assertFalse($this->service()->expire($offer));
        $this->assertSame(DeliveryOfferStatus::Expired, $offer->fresh()->status);
        // The driver is free for other offers again.
        $this->assertSame(0, DeliveryOffer::pending()->where('driver_id', $driver->id)->count());
    }

    public function test_attempts_per_round_are_capped_by_the_setting(): void
    {
        $this->setSetting('dispatch.max_attempts_per_round', 2);
        $order = $this->acceptedOrder();
        [$a, $b, $c] = [$this->newDriver(), $this->newDriver(), $this->newDriver()];

        $this->service()->reject($this->service()->offerByAdmin($order, $a, $this->admin), $a, 'busy');
        $this->service()->reject($this->service()->offerAutomatically($order, $b), $b, 'busy');
        $this->expectRule(422, fn () => $this->service()->offerAutomatically($order, $c));
        $this->assertSame(2, $order->fresh()->dispatch_attempts);
    }

    public function test_timeout_and_attempt_settings_are_clamped(): void
    {
        $this->setSetting('dispatch.offer_timeout_seconds', 5);
        $this->setSetting('dispatch.max_attempts_per_round', 999);
        $this->assertSame(30, DeliveryOfferService::timeoutSeconds());
        $this->assertSame(50, DeliveryOfferService::maxAttemptsPerRound());

        $this->setSetting('dispatch.offer_timeout_seconds', 120);
        $this->assertSame(120, DeliveryOfferService::timeoutSeconds());
    }

    public function test_ineligible_drivers_cannot_be_offered(): void
    {
        $order = $this->acceptedOrder();
        $busy = $this->newDriver();
        app(OrderAdminService::class)->assignDriver($this->acceptedOrder(), $busy, $this->admin);

        $cases = [
            'offline' => $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE]),
            'inactive' => $this->newDriver(['is_active' => false]),
            'other city' => $this->driver($this->city()),
            'already delivering' => $busy,
        ];
        foreach ($cases as $label => $driver) {
            $this->expectRule(422, fn () => $this->service()->offerByAdmin($order, $driver, $this->admin));
        }
        $this->assertSame(0, DeliveryOffer::where('order_id', $order->id)->count());
    }

    public function test_only_accepted_orders_can_be_offered_and_a_round_must_exist_for_auto_offers(): void
    {
        $driver = $this->newDriver();
        $pending = $this->order($this->customer(), $this->branch($this->store(), $this->cityModel), '10');
        $this->expectRule(422, fn () => $this->service()->offerByAdmin($pending, $driver, $this->admin));

        $this->expectRule(409, fn () => $this->service()->offerAutomatically($this->acceptedOrder(), $driver));
    }

    public function test_admins_without_the_assign_permission_cannot_offer(): void
    {
        $clerk = $this->employee(['orders.view']);
        $this->expectRule(403, fn () => $this->service()->offerByAdmin($this->acceptedOrder(), $this->newDriver(), $clerk));
    }

    public function test_a_driver_who_became_unavailable_cannot_accept(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);
        $driver->forceFill(['availability' => Driver::AVAILABILITY_OFFLINE])->save();

        $this->expectRule(409, fn () => $this->service()->accept($offer, $driver));
        $this->assertSame(DeliveryOfferStatus::Cancelled, $offer->fresh()->status);
        $this->assertSame(DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE, $offer->fresh()->cancel_reason);
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_cancelling_the_order_withdraws_the_pending_offer(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        app(OrderAdminService::class)->cancelByAdmin($order, $this->admin, ['reason_code' => 'other', 'note' => 'test']);

        $this->assertSame(DeliveryOfferStatus::Cancelled, $offer->fresh()->status);
        $this->assertSame(DeliveryOffer::CANCEL_ORDER_CANCELLED, $offer->fresh()->cancel_reason);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNull($order->fresh()->dispatch_state);
        $this->expectRule(409, fn () => $this->service()->accept($offer->fresh(), $driver));
    }

    public function test_a_direct_admin_assignment_overrides_a_pending_offer(): void
    {
        $order = $this->acceptedOrder();
        $offered = $this->newDriver();
        $chosen = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $offered, $this->admin);

        app(OrderAdminService::class)->assignDriver($order, $chosen, $this->admin);

        $this->assertSame(DeliveryOfferStatus::Cancelled, $offer->fresh()->status);
        $this->assertSame(DeliveryOffer::CANCEL_ADMIN_OVERRIDE, $offer->fresh()->cancel_reason);
        $this->assertSame($chosen->id, (int) $order->fresh()->driver_id);
        $this->assertNull($order->fresh()->dispatch_state);
        $this->expectRule(409, fn () => $this->service()->accept($offer->fresh(), $offered));
        $this->assertSame($chosen->id, (int) $order->fresh()->driver_id, 'The late accept did not steal the order.');
    }

    public function test_a_stale_second_acceptance_cannot_double_assign(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);
        $staleCopy = DeliveryOffer::findOrFail($offer->id); // loaded before the first accept

        $this->service()->accept($offer, $driver);
        $this->service()->accept($staleCopy, $driver); // re-read under lock: idempotent

        $this->assertCount(1, OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'assigned')->get());
        $this->assertSame(1, DeliveryOffer::where('order_id', $order->id)->where('status', 'accepted')->count());
    }

    public function test_the_no_driver_queue_changes_state_once(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->service()->offerByAdmin($order, $driver, $this->admin);

        $this->assertFalse($this->service()->markNoDriver($order), 'Not while an offer is pending.');
        $this->service()->reject($offer, $driver, 'busy');

        $this->assertTrue($this->service()->markNoDriver($order));
        $this->assertFalse($this->service()->markNoDriver($order), 'Unchanged condition: no second alert.');
        $queued = $order->fresh();
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $queued->dispatch_state);
        $this->assertNotNull($queued->no_driver_since);
        $this->assertSame(OrderStatus::Accepted, $queued->status, 'The order is kept, never dropped.');

        // A new offer takes it out of the queue.
        $this->service()->offerByAdmin($order, $this->newDriver(), $this->admin);
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state);
        $this->assertNull($order->fresh()->no_driver_since);
    }

    public function test_withdrawing_offers_for_an_order_or_a_driver(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $this->service()->offerByAdmin($order, $driver, $this->admin);

        $withdrawn = $this->service()->cancelPendingForDriver($driver);
        $this->assertSame(DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE, $withdrawn->cancel_reason);
        $this->assertNull($this->service()->cancelPendingForDriver($driver), 'Nothing left to withdraw.');
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state, 'The dispatch continues (B3 picks the next driver).');

        $this->service()->offerAutomatically($order, $this->newDriver());
        $restart = $this->service()->cancelPendingForOrder($order, DeliveryOffer::CANCEL_ADMIN_RESTART);
        $this->assertSame(DeliveryOffer::CANCEL_ADMIN_RESTART, $restart->cancel_reason);
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state);

        $this->service()->offerAutomatically($order, $this->newDriver());
        $this->service()->cancelPendingForOrder($order, DeliveryOffer::CANCEL_ADMIN_OVERRIDE);
        $this->assertNull($order->fresh()->dispatch_state);
    }
}
