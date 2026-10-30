<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PlusCycle;
use App\Models\PlusCycleDiscount;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\PlusStoreDiscount;
use App\Services\Order\OrderAdminService;
use App\Services\Plus\PlusService;
use Illuminate\Database\QueryException;
use LogicException;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — PLUS: request types, admin-confirmed cycles,
 * immutable snapshots with exceptions, renewal/change/cancel semantics,
 * expiry, free-delivery accounting.
 */
class PlusLifecycleTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function plan(int $free = 2): PlusPlan
    {
        return PlusPlan::create(['code' => 'gold-'.$free, 'name' => ['en' => 'Gold', 'ar' => 'ذهبي'], 'free_deliveries_per_cycle' => $free]);
    }

    protected function service(): PlusService
    {
        return app(PlusService::class);
    }

    public function test_new_request_creates_membership_and_snapshotted_cycle_with_exceptions(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $user = $this->customer();
        $plan = $this->plan(3);
        $storeA = $this->store();
        $storeB = $this->store();
        PlusStoreDiscount::create(['plus_plan_id' => $plan->id, 'store_id' => $storeA->id, 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount_amount' => 5]);
        PlusStoreDiscount::create(['plus_plan_id' => $plan->id, 'store_id' => $storeB->id, 'discount_type' => 'fixed', 'discount_value' => 2]);

        $request = PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id, 'customer_note' => 'please']);
        $cycle = $this->service()->approveNew($request, $admin, PlusMembership::POLICY_MONTHLY);

        $this->assertSame(PlusCycle::STATUS_ACTIVE, $cycle->status);
        $this->assertSame(3, $cycle->free_deliveries_total);
        $this->assertSame(2, $cycle->discounts()->count());
        $this->assertSame('approved', $request->fresh()->status);

        // Exceptions + plan changes apply to the NEXT cycle only.
        $membership = $cycle->membership;
        $this->service()->saveException($membership, $storeA, ['is_excluded' => true, 'note' => 'store request'], $admin);
        $this->service()->saveException($membership, $storeB, ['discount_type' => 'percent', 'discount_value' => 20, 'note' => 'vip'], $admin);
        $this->service()->setFreeDeliveriesOverride($membership, 6, $admin);
        PlusStoreDiscount::where('plus_plan_id', $plan->id)->where('store_id', $storeB->id)->update(['discount_value' => 9]);

        $this->assertSame(3, $cycle->fresh()->free_deliveries_total);
        $this->assertSame('2.0000', (string) PlusCycleDiscount::where('plus_cycle_id', $cycle->id)->where('store_id', $storeB->id)->value('snapshot_discount_value'));

        $renew = PlusRequest::create(['user_id' => $user->id, 'type' => 'renew']);
        $next = $this->service()->approveRenewal($renew, $admin);

        $this->assertSame(PlusCycle::STATUS_SCHEDULED, $next->status);
        $this->assertEquals($cycle->ends_at, $next->starts_at);
        $this->assertSame(6, $next->free_deliveries_total);
        $this->assertSame(1, $next->discounts()->count());
        $this->assertSame('exception', $next->discounts()->first()->source);
        $this->assertSame('20.0000', (string) $next->discounts()->first()->snapshot_discount_value);
    }

    public function test_snapshots_are_immutable(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $plan = $this->plan();
        PlusStoreDiscount::create(['plus_plan_id' => $plan->id, 'store_id' => $this->store()->id, 'discount_type' => 'fixed', 'discount_value' => 3]);
        $cycle = $this->service()->approveNew(PlusRequest::create(['user_id' => $this->customer()->id, 'type' => 'new', 'requested_plan_id' => $plan->id]), $admin, 'one_time');

        try {
            $cycle->update(['free_deliveries_total' => 99]);
            $this->fail('Cycle snapshot must be immutable.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $cycle->discounts()->first()->update(['snapshot_discount_value' => 1]);
    }

    public function test_change_applies_next_cycle_and_cancel_keeps_current_cycle_until_expiry(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $user = $this->customer();
        $gold = $this->plan(2);
        $silver = $this->plan(1);
        $cycle = $this->service()->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $gold->id]), $admin, 'custom', 10);
        $this->assertEquals($cycle->starts_at->copy()->addDays(10), $cycle->ends_at);

        $this->service()->approveChange(PlusRequest::create(['user_id' => $user->id, 'type' => 'change', 'requested_plan_id' => $silver->id]), $admin);
        $this->assertSame($gold->id, $cycle->fresh()->plus_plan_id);
        $this->assertSame($silver->id, $cycle->membership->fresh()->next_plan_id);

        $renewal = PlusRequest::create(['user_id' => $user->id, 'type' => 'renew']);
        $this->service()->approveCancellation(PlusRequest::create(['user_id' => $user->id, 'type' => 'cancel']), $admin);

        $membership = $cycle->membership->fresh();
        $this->assertSame(PlusMembership::STATUS_CANCELLED, $membership->status);
        $this->assertSame(PlusCycle::STATUS_ACTIVE, $cycle->fresh()->status);
        $this->assertSame('withdrawn', $renewal->fresh()->status);

        $this->service()->syncCycles(now()->addDays(11));
        $this->assertSame(PlusCycle::STATUS_EXPIRED, $cycle->fresh()->status);
        $this->assertSame(PlusMembership::STATUS_ENDED, $membership->fresh()->status);
    }

    public function test_free_deliveries_cannot_be_overconsumed_and_are_restored_on_cancellation(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $user = $this->customer();
        $cycle = $this->service()->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $this->plan(1)->id]), $admin, 'monthly');

        $this->assertTrue($this->service()->consumeFreeDelivery($cycle));
        $this->assertFalse($this->service()->consumeFreeDelivery($cycle));
        $this->assertSame(1, $cycle->fresh()->free_deliveries_used);

        $branch = $this->branch($this->store(), $this->city());
        $order = $this->order($user, $branch, '10', ['free_delivery' => $cycle]);
        $cancellation = app(OrderAdminService::class)->cancelByAdmin($order, $admin, ['reason_code' => 'other']);

        $this->assertTrue($cancellation->free_delivery_restored);
        $this->assertSame(0, $cycle->fresh()->free_deliveries_used);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_system_renewal_requests_and_one_pending_request_per_type(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $user = $this->customer();
        $cycle = $this->service()->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $this->plan()->id]), $admin, 'custom', 2);

        $stats = $this->service()->syncCycles(now());
        $this->assertSame(1, $stats['renewal_requests']);
        $this->assertSame('system', PlusRequest::where('type', 'renew')->value('source'));

        $this->expectException(QueryException::class);
        PlusRequest::create(['user_id' => $user->id, 'type' => 'renew']);
    }

    public function test_approval_requires_plus_approve_permission_and_valid_policy(): void
    {
        $this->usd();
        $request = PlusRequest::create(['user_id' => $this->customer()->id, 'type' => 'new', 'requested_plan_id' => $this->plan()->id]);

        try {
            $this->service()->approveNew($request, $this->employee(['plus.view']), 'monthly');
            $this->fail('plus.approve is required.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }

        $this->expectException(BusinessRuleException::class);
        $this->service()->approveNew($request, $this->employee(['plus.approve']), 'custom', null);
    }
}
