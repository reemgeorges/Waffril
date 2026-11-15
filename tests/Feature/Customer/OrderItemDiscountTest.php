<?php

namespace Tests\Feature\Customer;

use App\Models\BranchProduct;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlusCycle;
use App\Models\Product;
use App\Models\User;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receipt discount metadata (2026-11-15).
 *
 * Every order item placed now stores an IMMUTABLE snapshot of the offer that
 * produced its discount (offer_discount_type, offer_discount_percent), saved
 * at checkout inside the order transaction from the pricing calculation.
 * The API returns it as items[].offer {type, percent, source: "snapshot"}.
 * Items placed before the snapshot existed fall back to a VERIFIED current
 * offer (source "verified") or show only the exact amount. Nothing is
 * derived from amounts and no monetary value changes.
 */
class OrderItemDiscountTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['waffrli.currency.customer_currencies' => ['SYP'], 'waffrli.currency.operational_default' => 'SYP']);
    }

    /** A Damascus customer without PLUS (PLUS discounts disable offers). */
    protected function customer(): User
    {
        return User::query()->whereHas('addresses', fn ($a) => $a->where('is_default', true)->whereHas('city', fn ($c) => $c->where('name->en', 'Damascus')))
            ->whereNotIn('id', PlusCycle::query()->where('status', PlusCycle::STATUS_ACTIVE)->pluck('user_id'))
            ->where('email', 'like', 'customer-%@demo.waffrli.test')->orderBy('id')->firstOrFail();
    }

    /** Puts one product (required options picked) in a fresh cart; returns the cart. */
    protected function cartOf(string $token, User $user, string $storeSlug, string $productEn, int $qty): array
    {
        $cityId = $user->addresses()->where('is_default', true)->value('city_id');
        $product = Product::query()->whereHas('store', fn ($s) => $s->where('slug', $storeSlug))->where('name->en', $productEn)->firstOrFail();
        $bp = BranchProduct::query()->where('product_id', $product->id)->where('is_available', true)
            ->whereHas('branch', fn ($b) => $b->where('city_id', $cityId)->where('is_active', true))->firstOrFail();
        $options = $product->optionGroups()->where('is_active', true)->where('min_select', '>', 0)->with('options')->get()
            ->map(fn ($g) => ['id' => $g->options->first()->id, 'quantity' => 1])->values()->all();
        foreach ($this->api($token)->getJson('/api/v1/customer/carts')->json('data.carts') as $old) {
            $this->api($token)->deleteJson("/api/v1/customer/carts/{$old['id']}");
        }

        return $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $bp->branch_id, 'product_id' => $product->id, 'quantity' => $qty, 'options' => $options])
            ->assertOk()->json('data.cart');
    }

    /** Places an order for one product and returns [api order, model]. */
    protected function orderOf(string $token, User $user, string $storeSlug, string $productEn, int $qty): array
    {
        $cart = $this->cartOf($token, $user, $storeSlug, $productEn, $qty);
        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');

        return [$order, Order::with('items', 'currency', 'payments')->findOrFail($order['id'])];
    }

    protected function itemApi(string $token, int $orderId): array
    {
        return $this->api($token)->getJson("/api/v1/customer/orders/{$orderId}")->assertOk()->json('data.order.items.0');
    }

    public function test_percent_offer_snapshot_is_saved_at_checkout_with_exact_new_syp_amounts(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 2);
        $item = $order->items->sole();
        $line = $api['items'][0];

        // Stored snapshot: the offer's own percentage, DECIMAL(7,4), not derived from amounts.
        $this->assertSame('percent', $item->offer_discount_type);
        $this->assertTrue(Money::of((string) $item->offer_discount_percent)->isEqualTo('15'));
        $this->assertSame(['type' => 'percent', 'percent' => '15', 'source' => 'snapshot'], $line['offer']);
        // Exact snapshot amounts in NEW SYP (stored old ÷ 100), converted once.
        $this->assertSame(Denomination::presentString((string) $item->line_subtotal, $order->currency), $line['line_subtotal']);
        $this->assertSame(Denomination::presentString((string) $item->offer_discount_amount, $order->currency), $line['offer_discount']);
        $this->assertSame(Denomination::presentString((string) $item->line_total, $order->currency), $line['line_total']);
        $this->assertSame('SYP', $api['currency']['code']);
        $this->assertSame('new', $api['currency']['denomination']);
        $this->assertTrue(Money::of($line['line_subtotal'])->minus($line['offer_discount'])->isEqualTo($line['line_total']));
        // Order and payment totals are the pricing engine's, untouched.
        $this->assertTrue(Money::of((string) $order->subtotal)->minus((string) $order->offer_discount_total)->minus((string) $order->plus_discount_amount)
            ->plus((string) $order->delivery_fee)->isEqualTo((string) $order->total));
        $this->assertTrue(Money::of((string) $order->payments->sum('amount'))->isEqualTo((string) $order->total));
        $this->assertSame(Denomination::presentString((string) $order->total, $order->currency), $api['amounts']['total']);
        // Backward compatible: the earlier fields are all still there.
        foreach (['product_id', 'name', 'quantity', 'unit_price', 'line_total', 'notes', 'options'] as $key) {
            $this->assertArrayHasKey($key, $line);
        }
    }

    public function test_fixed_offer_snapshot_has_no_percentage(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-pizza-roma', 'Garlic bread', 3);
        $item = $order->items->sole();

        $this->assertSame('fixed', $item->offer_discount_type);
        $this->assertNull($item->offer_discount_percent);
        $this->assertSame(['type' => 'fixed', 'percent' => null, 'source' => 'snapshot'], $api['items'][0]['offer']);
        // 0.30 USD × 13000 = 3,900 old SYP per unit × 3 = 11,700 old = 117.00 new.
        $this->assertSame('117.00', $api['items'][0]['offer_discount']);
    }

    public function test_items_without_an_offer_have_no_discount_metadata(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Hummus', 1);
        $item = $order->items->sole();

        $this->assertNull($item->offer_id);
        $this->assertNull($item->offer_discount_type);
        $this->assertNull($item->offer_discount_percent);
        $this->assertNull($api['items'][0]['offer']);
        $this->assertSame('0.00', $api['items'][0]['offer_discount']);
        $this->assertSame($api['items'][0]['line_subtotal'], $api['items'][0]['line_total']);
    }

    public function test_the_snapshot_survives_editing_disabling_and_deleting_the_offer(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 1);
        $offerId = $order->items->sole()->offer_id;
        $rows = fn () => DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($r) => (array) $r)->all();
        $before = $rows();
        $expected = $api['items'][0];

        Offer::query()->whereKey($offerId)->update(['discount_type' => 'fixed', 'discount_value' => '0.50']);
        $this->assertSame($expected, $this->itemApi($token, $order->id), 'edited offer');
        Offer::query()->whereKey($offerId)->update(['is_active' => false]);
        $this->assertSame($expected, $this->itemApi($token, $order->id), 'disabled offer');
        Offer::query()->findOrFail($offerId)->delete();
        $this->assertSame($expected, $this->itemApi($token, $order->id), 'deleted offer');

        $this->assertSame(['type' => 'percent', 'percent' => '15', 'source' => 'snapshot'], $expected['offer']);
        $this->assertSame($before, $rows(), 'Nothing about the order item was rewritten.');
    }

    public function test_legacy_items_fall_back_to_a_verified_offer_or_show_the_amount_only(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 1);
        // Test-only: make this item look like one placed before the snapshot existed.
        DB::table('order_items')->where('order_id', $order->id)->update(['offer_discount_type' => null, 'offer_discount_percent' => null]);

        $legacy = $this->itemApi($token, $order->id);
        $this->assertSame(['type' => 'percent', 'percent' => '15', 'source' => 'verified'], $legacy['offer'],
            'The current offer still reproduces the stored amount: reported as verified, not as a snapshot.');

        Offer::query()->whereKey($order->items->sole()->offer_id)->update(['discount_value' => '20']);
        $edited = $this->itemApi($token, $order->id);
        $this->assertNull($edited['offer'], 'No percentage is invented for a legacy item whose offer changed.');
        $this->assertSame($api['items'][0]['offer_discount'], $edited['offer_discount'], 'The exact monetary discount is still shown.');
        // Never backfilled.
        $this->assertNull(OrderItem::query()->where('order_id', $order->id)->value('offer_discount_type'));
    }

    public function test_a_failed_checkout_leaves_no_order_items_or_snapshots(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        $cart = $this->cartOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 1);
        $items = OrderItem::query()->count();
        $orders = Order::query()->count();

        // More wallet money than the customer has: the whole transaction rolls back.
        $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['wallet_amount' => '999999'])->assertStatus(422);

        $this->assertSame($items, OrderItem::query()->count());
        $this->assertSame($orders, Order::query()->count());
    }
}
