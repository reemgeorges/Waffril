<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\PlusStoreDiscount;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\Order\OrderPricingService;
use App\Services\Plus\PlusService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

/**
 * WAFFRLI PLUS benefits (2026-11-12), on the seeded demo data:
 *  1. free deliveries — allowance from the platform setting (default 10);
 *  2. delivery-fee discount once the free deliveries are used up (default 50%);
 *  3. a store's own PLUS discount on the merchandise subtotal (members only);
 *  plus the WhatsApp subscription contact, which never activates anything.
 */
class PlusBenefitsTest extends CustomerTestCase
{
    protected function member(): User
    {
        return $this->demoCustomer(1); // demo Gold member with an active cycle
    }

    protected function cycle(User $user): PlusCycle
    {
        return PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)->firstOrFail();
    }

    protected function setting(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** A product that can be ordered without choosing options. */
    protected function line(Branch $branch): BranchProduct
    {
        return BranchProduct::query()->where('branch_id', $branch->id)->where('is_available', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true)
                ->whereDoesntHave('optionGroups', fn ($g) => $g->where('is_active', true)->where('min_select', '>', 0)))
            ->orderBy('id')->firstOrFail();
    }

    /** A branch in the customer's city whose store has NO plan/member rule in the given cycle. */
    protected function branchWithoutCycleRule(User $user, ?PlusCycle $cycle = null): Branch
    {
        $city = $user->addresses()->where('is_default', true)->firstOrFail()->city_id;
        $ruled = $cycle ? $cycle->discounts()->pluck('store_id')->all() : [];

        return Branch::query()->where('city_id', $city)->where('is_active', true)
            ->whereHas('store', fn ($q) => $q->where('is_active', true)->whereNotIn('id', $ruled))
            ->whereHas('branchProducts', fn ($q) => $q->where('is_available', true))
            ->get()->first(fn (Branch $b) => BranchProduct::query()->where('branch_id', $b->id)->where('is_available', true)
                ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereDoesntHave('optionGroups', fn ($g) => $g->where('is_active', true)->where('min_select', '>', 0)))
                ->exists()) ?? $this->fail('No suitable demo branch.');
    }

    protected function price(User $user, Branch $branch, bool $free = false, int $quantity = 2): array
    {
        return app(OrderPricingService::class)->price($user, $branch, [['branch_product_id' => $this->line($branch)->id, 'quantity' => $quantity]],
            Currency::query()->default()->firstOrFail(), $user->addresses()->where('is_default', true)->first(), $free);
    }

    protected function pct(BigDecimal $amount, string $percent, int $decimals): BigDecimal
    {
        return Money::roundTo($amount->multipliedBy($percent)->dividedBy(100, 8, \Brick\Math\RoundingMode::HALF_UP), $decimals);
    }

    // ------------------------------------------------------------ 1. free deliveries

    public function test_new_cycles_get_the_global_allowance_default_ten_and_admins_can_change_it(): void
    {
        $this->assertSame(10, PlusService::freeDeliveriesLimit(), 'Default allowance.');
        $this->assertSame(50, PlusService::deliveryDiscountPercent(), 'Default delivery discount.');

        $admin = $this->superAdmin();
        $user = $this->demoCustomer(10); // not a member
        $plan = PlusPlan::query()->where('is_active', true)->firstOrFail();
        $this->setting('plus.free_deliveries_limit', 15);
        $this->setting('plus.delivery_discount_percentage', 30);

        $cycle = app(PlusService::class)->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id]), $admin, PlusMembership::POLICY_MONTHLY);
        $this->assertSame(15, $cycle->free_deliveries_total, 'The setting, not the plan, decides.');
        $this->assertSame('30.00', (string) $cycle->delivery_discount_percent);

        // A later change does not touch a confirmed cycle.
        $this->setting('plus.free_deliveries_limit', 5);
        $this->setting('plus.delivery_discount_percentage', 80);
        $this->assertSame(15, $cycle->fresh()->free_deliveries_total);
        $this->assertSame('30.00', PlusService::cycleDeliveryDiscountPercent($cycle->fresh()), 'The snapshot (30%), not the new setting (80%).');
    }

    public function test_free_delivery_makes_the_fee_zero_without_touching_the_bill(): void
    {
        $user = $this->member();
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => 0])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);

        $free = $this->price($user, $branch, true);
        $paid = $this->price($user, $branch, false);

        $this->assertTrue($free['free_delivery_applied']);
        $this->assertTrue($free['delivery_fee']->isZero());
        $this->assertTrue($free['plus_delivery_discount_amount']->isZero(), 'Never both on one fee.');
        $this->assertTrue($free['subtotal']->isEqualTo($paid['subtotal']), 'The bill is independent of the delivery fee.');
        $this->assertSame($cycle->free_deliveries_total, $free['free_deliveries_remaining']);
        // Free deliveries left but not used on this order: normal fee, no percentage discount.
        $this->assertTrue($paid['delivery_fee']->isEqualTo($paid['delivery_fee_original']));
        $this->assertNull($paid['plus_delivery_discount_percent']);
    }

    // ------------------------------------------------------------ 2. delivery discount

    public function test_delivery_discount_applies_only_after_the_free_deliveries_are_used_up(): void
    {
        $user = $this->member();
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => $cycle->free_deliveries_total])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);
        $decimals = Currency::query()->default()->firstOrFail()->decimals;

        $priced = $this->price($user, $branch, true);
        $original = $priced['delivery_fee_original'];
        $this->assertTrue($original->isPositive(), 'Demo delivery has a fee.');
        $percent = PlusService::cycleDeliveryDiscountPercent($cycle);

        $this->assertFalse($priced['free_delivery_applied']);
        $this->assertSame(0, $priced['free_deliveries_remaining']);
        $this->assertTrue($priced['plus_delivery_discount_amount']->isEqualTo($this->pct($original, $percent, $decimals)));
        $this->assertTrue($priced['delivery_fee']->isEqualTo($original->minus($priced['plus_delivery_discount_amount'])));
        $this->assertFalse($priced['delivery_fee']->isNegative());
        $this->assertTrue($priced['total']->isEqualTo($priced['subtotal']->minus($priced['offer_discount_total'])->minus($priced['plus_discount_amount'])->plus($priced['delivery_fee'])),
            'The percentage discounts the delivery fee only.');
    }

    public function test_delivery_discount_of_100_percent_never_goes_below_zero_and_0_percent_gives_nothing(): void
    {
        $user = $this->member();
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => $cycle->free_deliveries_total])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);

        PlusCycle::query()->whereKey($cycle->id)->update(['delivery_discount_percent' => 100]);
        $all = $this->price($user, $branch);
        $this->assertTrue($all['delivery_fee']->isZero());
        $this->assertTrue($all['plus_delivery_discount_amount']->isEqualTo($all['delivery_fee_original']));

        PlusCycle::query()->whereKey($cycle->id)->update(['delivery_discount_percent' => 0]);
        $none = $this->price($user, $branch);
        $this->assertTrue($none['delivery_fee']->isEqualTo($none['delivery_fee_original']));
        $this->assertNull($none['plus_delivery_discount_percent']);
    }

    public function test_non_members_and_expired_members_get_no_plus_benefit(): void
    {
        $member = $this->member();
        $cycle = $this->cycle($member);
        $branch = $this->branchWithoutCycleRule($member, $cycle);
        $branch->store->forceFill(['plus_discount_percent' => 5])->save();

        foreach ([$this->demoCustomer(10)] as $nonMember) {
            $priced = $this->price($nonMember, $this->branchWithoutCycleRule($nonMember), true);
            $this->assertTrue($priced['plus_discount_amount']->isZero());
            $this->assertFalse($priced['free_delivery_applied']);
            $this->assertTrue($priced['plus_delivery_discount_amount']->isZero());
        }

        // Expired: the cycle window is over.
        $cycle->forceFill(['free_deliveries_used' => $cycle->free_deliveries_total])->save();
        PlusCycle::query()->whereKey($cycle->id)->update(['ends_at' => now()->subMinute()]);
        $priced = $this->price($member, $branch, true);
        $this->assertTrue($priced['plus_discount_amount']->isZero());
        $this->assertTrue($priced['plus_delivery_discount_amount']->isZero());
        $this->assertTrue($priced['delivery_fee']->isEqualTo($priced['delivery_fee_original']));
    }

    // ------------------------------------------------------------ 3. store discounts

    public function test_each_store_gives_its_own_percentage_on_merchandise_only(): void
    {
        $user = $this->member();
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => 0])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);
        $decimals = Currency::query()->default()->firstOrFail()->decimals;

        foreach (['2', '5', '12.5'] as $percent) {
            $branch->store->forceFill(['plus_discount_percent' => $percent])->save();
            $priced = $this->price($user, $branch->fresh(), true);
            $this->assertTrue($priced['plus_discount_amount']->isEqualTo($this->pct($priced['subtotal'], $percent, $decimals)), "{$percent}%");
            $this->assertSame($percent, $priced['plus_discount_percent']);
            $this->assertTrue($priced['offer_discount_total']->isZero(), 'PLUS has priority over public offers (no double discount).');
            $this->assertTrue($priced['delivery_fee']->isZero(), 'Free delivery combines with the store discount.');
            $this->assertTrue($priced['total']->isEqualTo($priced['subtotal']->minus($priced['plus_discount_amount'])));
        }

        // Another store's percentage never leaks: a store with 0% gives nothing.
        $branch->store->forceFill(['plus_discount_percent' => 0])->save();
        $this->assertTrue($this->price($user, $branch->fresh(), true)['plus_discount_amount']->isZero());
    }

    public function test_plan_rules_win_and_member_exclusions_block_the_store_discount(): void
    {
        $user = $this->member();
        $cycle = $this->cycle($user);
        $ruled = $cycle->discounts()->with('store')->firstOrFail();
        $ruled->store->forceFill(['plus_discount_percent' => 50])->save();
        $branch = Branch::query()->where('store_id', $ruled->store_id)->whereHas('branchProducts', fn ($q) => $q->where('is_available', true))->firstOrFail();

        $priced = $this->price($user, $branch);
        $this->assertSame($ruled->id, $priced['plus_cycle_discount']?->id, 'The cycle snapshot rule applies, not the store default.');

        $other = $this->branchWithoutCycleRule($user, $cycle);
        $other->store->forceFill(['plus_discount_percent' => 5])->save();
        PlusStoreDiscount::create(['plus_membership_id' => $cycle->plus_membership_id, 'store_id' => $other->store_id, 'is_excluded' => true, 'is_active' => true]);
        $this->assertTrue($this->price($user, $other->fresh())['plus_discount_amount']->isZero(), 'An approved exclusion blocks the store discount.');
    }

    // ------------------------------------------------------------ checkout and order snapshot

    public function test_checkout_persists_a_consistent_snapshot_and_history_stays_unchanged(): void
    {
        $user = $this->member();
        $token = $this->loginCustomer($user);
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => $cycle->free_deliveries_total])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);
        $branch->store->forceFill(['plus_discount_percent' => 5])->save();
        $bp = $this->line($branch);

        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $branch->id, 'product_id' => $bp->product_id, 'quantity' => 2])->assertOk()->json('data.cart');
        $preview = $this->api($token)->getJson("/api/v1/customer/carts/{$cart['id']}")->assertOk()->json('data.cart.preview');
        $this->assertSame('5', $preview['plus_discount_percent']);
        $this->assertNotNull($preview['plus_delivery_discount_percent']);
        $this->assertSame(0, $preview['free_deliveries_remaining']);
        $this->assertNull($preview['with_free_delivery']);

        $data = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['use_free_delivery' => true])->assertOk()->json('data.order');
        $order = Order::query()->with('payments')->findOrFail($data['id']);

        $this->assertSame($preview['total'], $data['amounts']['total'], 'Preview and checkout agree.');
        $this->assertSame('5', $data['amounts']['plus_discount_percent']);
        $this->assertSame($preview['plus_delivery_discount'], $data['amounts']['plus_delivery_discount']);
        $this->assertSame($cycle->id, $order->plus_cycle_id);
        $total = BigDecimal::of((string) $order->subtotal)->minus((string) $order->offer_discount_total)->minus((string) $order->plus_discount_amount)->plus((string) $order->delivery_fee);
        $this->assertTrue($total->isEqualTo((string) $order->total));
        $this->assertTrue(BigDecimal::of((string) $order->delivery_fee_original)->minus((string) $order->plus_delivery_discount_amount)->isEqualTo((string) $order->delivery_fee));
        $paid = $order->payments->reduce(fn ($sum, $p) => $sum->plus((string) $p->amount), BigDecimal::zero());
        $this->assertTrue($paid->isEqualTo((string) $order->total), 'Cash to collect = order total (driver collection unchanged).');

        // Later changes never rewrite the order.
        $branch->store->forceFill(['plus_discount_percent' => 40])->save();
        $this->setting('plus.delivery_discount_percentage', 10);
        $again = $this->api($token)->getJson("/api/v1/customer/orders/{$order->id}")->assertOk()->json('data.order.amounts');
        $this->assertSame($data['amounts'], $again);
    }

    public function test_cart_preview_shows_the_free_delivery_variant_priced_by_the_server(): void
    {
        $user = $this->member();
        $token = $this->loginCustomer($user);
        $cycle = $this->cycle($user);
        $cycle->forceFill(['free_deliveries_used' => 0])->save();
        $branch = $this->branchWithoutCycleRule($user, $cycle);
        $bp = $this->line($branch);
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $branch->id, 'product_id' => $bp->product_id, 'quantity' => 1])->assertOk()->json('data.cart');

        $preview = $this->api($token)->getJson("/api/v1/customer/carts/{$cart['id']}")->assertOk()->json('data.cart.preview');
        $this->assertTrue($preview['free_delivery_available']);
        $this->assertSame($cycle->free_deliveries_total, $preview['free_deliveries_remaining']);
        $this->assertSame('0.00', Money::roundTo($preview['with_free_delivery']['delivery_fee'], 2)->__toString());
        $this->assertSame(0, $cycle->fresh()->free_deliveries_used, 'A preview never consumes a free delivery.');
    }

    // ------------------------------------------------------------ subscription via WhatsApp

    public function test_plans_expose_the_program_and_whatsapp_link_only_when_configured(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(10));
        $program = $this->api($token)->getJson('/api/v1/customer/plus/plans')->assertOk()->json('data.program');
        $this->assertSame(10, $program['free_deliveries_per_cycle']);
        $this->assertSame(50, $program['delivery_discount_percent']);
        $this->assertNull($program['subscription']['whatsapp_number']);
        $this->assertNull($program['subscription']['whatsapp_url'], 'No invalid link without a configured number.');
        $this->assertSame('مرحبا ارغب في الاشتراك في وفرلي بلس', $program['subscription']['message']);

        $this->setting('plus.subscription_whatsapp', '+963912345678');
        $program = $this->api($token)->getJson('/api/v1/customer/plus/plans')->assertOk()->json('data.program');
        $this->assertSame('+963912345678', $program['subscription']['whatsapp_number']);
        $this->assertSame('https://wa.me/963912345678?text='.rawurlencode('مرحبا ارغب في الاشتراك في وفرلي بلس'), $program['subscription']['whatsapp_url']);
    }

    public function test_a_subscription_request_never_activates_plus_and_is_not_duplicated(): void
    {
        $user = $this->demoCustomer(10);
        $token = $this->loginCustomer($user);
        $plan = PlusPlan::query()->where('is_active', true)->firstOrFail();

        $this->api($token)->postJson('/api/v1/customer/plus/requests', ['type' => 'new', 'plan_id' => $plan->id])->assertOk();
        $this->api($token)->postJson('/api/v1/customer/plus/requests', ['type' => 'new', 'plan_id' => $plan->id])->assertStatus(409);

        $this->assertSame(1, PlusRequest::query()->where('user_id', $user->id)->where('status', 'pending')->count());
        $this->assertFalse(PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)->exists(), 'Pending is not active.');
        $priced = $this->price($user, $this->branchWithoutCycleRule($user), true);
        $this->assertFalse($priced['free_delivery_applied']);
        $this->assertTrue($priced['plus_discount_amount']->isZero());
    }

    public function test_catalog_shows_the_store_plus_percentage(): void
    {
        $user = $this->member();
        $token = $this->loginCustomer($user);
        $branch = $this->branchWithoutCycleRule($user);
        $branch->store->forceFill(['plus_discount_percent' => '5.00'])->save();

        $found = collect($this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->assertOk()->json('data.branches'))
            ->firstWhere('id', $branch->id);
        $this->assertSame('5', $found['store']['plus_discount_percent']);

        $branch->store->forceFill(['plus_discount_percent' => 0])->save();
        $found = collect($this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->json('data.branches'))->firstWhere('id', $branch->id);
        $this->assertNull($found['store']['plus_discount_percent']);
    }

    protected function superAdmin(): \App\Models\Admin
    {
        (new \Database\Seeders\AdminAccessSeeder)->run();
        $admin = \App\Models\Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(\App\Models\Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }
}
