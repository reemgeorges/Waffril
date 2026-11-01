<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BranchHourException;
use App\Models\BranchProduct;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Setting;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Order\OrderAdminService;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — admin order workflow: acceptance (incl.
 * scheduled window and availability), recommended assignment, transition
 * guards, urgent flagging, customer cancellation rules and requests.
 */
class OrderWorkflowTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function service(): OrderAdminService
    {
        return app(OrderAdminService::class);
    }

    public function test_accept_assign_pickup_deliver_collects_cash_and_records_history(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $order = $this->order($this->customer(), $branch, '12.5');
        $driver = $this->driver($city);

        $this->service()->accept($order, $admin);
        $this->assertSame($driver->id, app(DriverRecommendationService::class)->recommend($order->fresh())->first()['driver']->id);
        $this->service()->assignDriver($order->fresh(), $driver, $admin);
        $this->service()->markPickedUp($order->fresh(), $driver);
        $this->service()->markDelivered($order->fresh(), $driver);

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(['accepted', 'assigned', 'picked_up', 'delivered'], $order->statusHistories()->pluck('to_status')->all());
        $this->assertSame('12.5000', (string) $order->payments()->first()->collected_amount);
        $this->assertSame($driver->id, $order->payments()->first()->collected_by_driver_id);
    }

    public function test_scheduled_order_cannot_be_accepted_before_window_and_branch_hours_apply(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());
        Setting::create(['key' => 'orders.scheduled_acceptance_window_minutes', 'value' => 30]);

        $later = $this->order($this->customer(), $branch, '10', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->addHours(3)]);
        try {
            $this->service()->accept($later, $admin);
            $this->fail('Acceptance before the window must be refused.');
        } catch (BusinessRuleException) {
            $this->assertSame(OrderStatus::Pending, $later->fresh()->status);
        }

        $soon = $this->order($this->customer(), $branch, '10', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->addMinutes(20)]);
        BranchHourException::create(['branch_id' => $branch->id, 'kind' => 'closed', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'created_by_admin_id' => $admin->id]);
        $this->expectException(BusinessRuleException::class);
        $this->service()->accept($soon, $admin);
    }

    public function test_unavailable_item_blocks_acceptance_so_admin_cancels_instead(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());
        $order = $this->order($this->customer(), $branch, '10');
        BranchProduct::query()->update(['is_available' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->service()->accept($order, $admin);
    }

    public function test_ineligible_driver_and_illegal_transitions_are_refused(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $order = $this->order($this->customer(), $branch, '10');

        try {
            $this->service()->assignDriver($order, $this->driver($city), $admin);
            $this->fail('A pending order cannot get a driver.');
        } catch (BusinessRuleException) {
        }

        $this->service()->accept($order, $admin);
        $otherCity = $this->driver($this->city(['name' => ['en' => 'Aleppo', 'ar' => 'حلب']]));
        $offline = $this->driver($city, ['availability' => Driver::AVAILABILITY_OFFLINE]);

        foreach ([$otherCity, $offline] as $driver) {
            try {
                $this->service()->assignDriver($order->fresh(), $driver, $admin);
                $this->fail('Ineligible driver must be refused.');
            } catch (BusinessRuleException) {
            }
        }
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_actions_check_their_own_permissions(): void
    {
        $branch = $this->branch($this->store(), $this->city());
        $order = $this->order($this->customer(), $branch, '10');

        try {
            $this->service()->accept($order, $this->employee(['orders.view']));
            $this->fail('orders.accept is required.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }

        $this->service()->accept($order, $this->employee(['orders.accept']));
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_late_scheduled_orders_are_flagged_urgent_never_cancelled(): void
    {
        $branch = $this->branch($this->store(), $this->city());
        $late = $this->order($this->customer(), $branch, '10', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->subMinutes(5)]);

        $this->assertSame(1, $this->service()->flagLateScheduledOrders());
        $this->assertNotNull($late->fresh()->urgent_at);
        $this->assertSame(OrderStatus::Pending, $late->fresh()->status);
    }

    public function test_customer_cancels_directly_only_while_pending_otherwise_via_request(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());

        $pending = $this->order($this->customer(), $branch, '10');
        $this->assertSame('customer', $this->service()->cancelByCustomer($pending)->cancelled_by);

        $accepted = $this->order($this->customer(), $branch, '10');
        $this->service()->accept($accepted, $admin);
        try {
            $this->service()->cancelByCustomer($accepted->fresh());
            $this->fail('After acceptance the customer needs approval.');
        } catch (BusinessRuleException) {
        }

        $request = OrderCancellationRequest::create(['order_id' => $accepted->id, 'customer_note' => 'changed my mind']);
        $this->service()->rejectCancellationRequest($request, $admin, 'already prepared');
        $this->assertSame(OrderStatus::Accepted, $accepted->fresh()->status);

        $second = OrderCancellationRequest::create(['order_id' => $accepted->id]);
        $cancellation = $this->service()->approveCancellationRequest($second, $admin, ['note' => 'ok']);
        $this->assertSame($second->id, $cancellation->cancellation_request_id);
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $accepted->fresh()->status);
    }
}
