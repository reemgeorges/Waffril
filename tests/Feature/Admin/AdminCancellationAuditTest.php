<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationRequest;
use App\Models\OrderRefund;
use App\Models\Wallet;
use App\Services\Order\OrderAdminService;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-16, Phase 10) — admin cancellation audit: concurrency
 * between approving and rejecting a customer's request, requests that
 * used to stay pending forever, idempotent double submits, permissions
 * and money handling after pickup (collected cash refunds go to the
 * customer's wallet only by an explicit, permitted admin action).
 */
class AdminCancellationAuditTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function service(): OrderAdminService
    {
        return app(OrderAdminService::class);
    }

    private function acceptedOrder(): array
    {
        $admin = $this->superAdmin();
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->city()), '10');
        $this->service()->accept($order, $admin);

        return [$order->fresh(), $admin];
    }

    public function test_a_stale_approval_never_overrides_a_rejection(): void
    {
        [$order, $admin] = $this->acceptedOrder();
        $request = OrderCancellationRequest::create(['order_id' => $order->id]);
        $stale = $request->fresh();   // admin A opened the approve dialog

        $this->service()->rejectCancellationRequest($request, $this->superAdmin(), 'already prepared');   // admin B

        try {
            // What approveCancellationRequest does after its (unlocked) pre-check.
            $this->service()->cancelByAdmin($order, $admin, ['reason_code' => 'customer_request'], $stale);
            $this->fail('A rejected request must not be approved.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(__('admin.errors.request_not_pending'), $e->getMessage());
        }

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertSame(0, OrderCancellation::count());
    }

    public function test_direct_admin_cancellation_answers_the_open_customer_request(): void
    {
        [$order, $admin] = $this->acceptedOrder();
        $request = OrderCancellationRequest::create(['order_id' => $order->id, 'customer_note' => 'please']);

        $cancellation = $this->service()->cancelByAdmin($order, $admin, ['reason_code' => 'customer_request']);

        $this->assertSame($request->id, $cancellation->cancellation_request_id);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->resolved_by_admin_id);
        $this->assertSame(0, OrderCancellationRequest::where('status', 'pending')->count());
    }

    public function test_delivery_closes_an_open_request_as_rejected(): void
    {
        [$order, $admin] = $this->acceptedOrder();
        $driver = $this->driver($order->branch->city);
        $this->service()->assignDriver($order, $driver, $admin);
        $this->service()->markPickedUp($order->fresh(), $driver);
        $request = OrderCancellationRequest::create(['order_id' => $order->id]);

        $this->service()->markDelivered($order->fresh(), $driver);

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertNotNull($request->resolved_at);
        $this->assertNull($request->resolved_by_admin_id);
    }

    public function test_double_submit_with_the_same_key_cancels_once(): void
    {
        [$order, $admin] = $this->acceptedOrder();
        $data = ['reason_code' => 'customer_request', 'idempotency_key' => 'cancel-form-1'];

        $first = $this->service()->cancelByAdmin($order, $admin, $data);
        $second = $this->service()->cancelByAdmin($order, $admin, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderCancellation::count());
        $this->assertSame(1, $order->statusHistories()->where('to_status', 'cancelled')->count());
    }

    public function test_permissions_are_enforced_by_the_service(): void
    {
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $pickedUp = $this->order($this->customer(), $branch, '10', ['status' => OrderStatus::PickedUp]);

        foreach ([[], ['orders.view'], ['orders.cancel']] as $permissions) {
            try {
                $this->service()->cancelByAdmin($pickedUp, $this->employee($permissions), ['reason_code' => 'customer_request', 'driver_handling' => 'returned_to_branch', 'note' => 'x']);
                $this->fail('Refused for '.json_encode($permissions));
            } catch (BusinessRuleException $e) {
                $this->assertSame(403, $e->getStatus(), json_encode($permissions));
            }
        }
        $this->assertSame(OrderStatus::PickedUp, $pickedUp->fresh()->status);

        $request = OrderCancellationRequest::create(['order_id' => $pickedUp->id]);
        $this->expectException(BusinessRuleException::class);
        $this->service()->approveCancellationRequest($request, $this->employee(['orders.cancel', 'orders.cancel_after_pickup']), []);
    }

    public function test_collected_cash_refund_after_pickup_needs_refund_permission_and_goes_to_the_wallet(): void
    {
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $customer = $this->customer();
        $order = $this->order($customer, $branch, '10', ['status' => OrderStatus::PickedUp]);
        $data = ['reason_code' => 'customer_request', 'driver_handling' => 'returned_to_branch', 'note' => 'gate closed',
            'cash_collected_amount' => '10', 'refund_collected_cash_amount' => '4'];

        // orders.cancel + after pickup but no refunds.create: refused and nothing changed (one transaction).
        try {
            $this->service()->cancelByAdmin($order, $this->employee(['orders.cancel', 'orders.cancel_after_pickup']), $data);
            $this->fail('Refunding needs refunds.create.');
        } catch (BusinessRuleException) {
        }
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
        $this->assertSame(0, OrderCancellation::count());
        $this->assertSame('pending', $order->payments()->first()->status);

        // More than collected: refused.
        try {
            $this->service()->cancelByAdmin($order, $this->superAdmin(), array_merge($data, ['refund_collected_cash_amount' => '11']));
            $this->fail('Cannot refund more than collected.');
        } catch (BusinessRuleException) {
        }
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);

        $cancellation = $this->service()->cancelByAdmin($order, $this->superAdmin(), $data);
        $refund = OrderRefund::sole();
        $this->assertSame($cancellation->id, $refund->order_cancellation_id);
        $this->assertSame('4.0000', (string) $refund->amount);
        $this->assertSame('10.0000', (string) $cancellation->cash_collected_amount);
        $this->assertSame('4.0000', (string) Wallet::where('user_id', $customer->id)->value('balance'));
    }
}
