<?php

namespace Tests\Feature\Customer;

use App\Models\BranchProduct;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PlusCycle;
use App\Models\Product;
use App\Models\User;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receipt discount metadata (2026-11-15): each order item exposes its line
 * subtotal, its exact offer discount and — only when VERIFIED against the
 * stored discount — the offer's type and percentage. Nothing is derived
 * from amounts and no snapshot is changed.
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

    /** Places an order for one product of a store (no required options) and returns [api order, model]. */
    protected function orderOf(string $token, User $user, string $storeSlug, string $productEn, int $qty): array
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
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $bp->branch_id, 'product_id' => $product->id, 'quantity' => $qty, 'options' => $options])
            ->assertOk()->json('data.cart');
        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');

        return [$order, Order::with('items', 'currency')->findOrFail($order['id'])];
    }

    public function test_percent_offer_exposes_its_verified_percentage_and_exact_amounts(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 2);
        $item = $order->items->sole();
        $line = $api['items'][0];

        $this->assertNotNull($item->offer_id);
        $this->assertSame(['type' => 'percent', 'percent' => '15'], $line['offer']);
        // Exact snapshot amounts in NEW SYP (stored old ÷ 100), converted once.
        $this->assertSame(Denomination::presentString((string) $item->line_subtotal, $order->currency), $line['line_subtotal']);
        $this->assertSame(Denomination::presentString((string) $item->offer_discount_amount, $order->currency), $line['offer_discount']);
        $this->assertSame(Denomination::presentString((string) $item->line_total, $order->currency), $line['line_total']);
        $this->assertSame('2', (string) $item->quantity);
        // line_subtotal − offer_discount = line_total, and the order total is untouched.
        $this->assertTrue(Money::of($line['line_subtotal'])->minus($line['offer_discount'])->isEqualTo($line['line_total']));
        $this->assertSame(Denomination::presentString((string) $order->total, $order->currency), $api['amounts']['total']);
        // Backward compatible: the earlier fields are still there.
        foreach (['product_id', 'name', 'quantity', 'unit_price', 'line_total', 'notes', 'options'] as $key) {
            $this->assertArrayHasKey($key, $line);
        }
    }

    public function test_an_offer_edited_after_the_order_is_not_reported_and_nothing_is_rewritten(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-shami-grill', 'Mixed grill platter', 1);
        $before = DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($r) => (array) $r)->all();

        Offer::query()->whereKey($order->items->sole()->offer_id)->update(['discount_value' => '20']);
        $line = $this->api($token)->getJson("/api/v1/customer/orders/{$order->id}")->assertOk()->json('data.order.items.0');

        $this->assertNull($line['offer'], 'An edited offer no longer reproduces the stored discount: no percentage is invented.');
        $this->assertSame($api['items'][0]['offer_discount'], $line['offer_discount'], 'The exact monetary discount is still shown.');
        $this->assertSame($before, DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_fixed_offer_is_reported_as_fixed_without_a_percentage(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api, $order] = $this->orderOf($token, $user, 'demo-pizza-roma', 'Garlic bread', 3);
        $item = $order->items->sole();
        $line = $api['items'][0];

        $this->assertSame(['type' => 'fixed', 'percent' => null], $line['offer']);
        // 0.30 USD × 13000 = 3,900 old SYP per unit × 3 = 11,700 old = 117.00 new.
        $this->assertSame(Denomination::presentString((string) $item->offer_discount_amount, $order->currency), $line['offer_discount']);
        $this->assertSame('117.00', $line['offer_discount']);
    }

    public function test_lines_without_an_offer_report_none(): void
    {
        $user = $this->customer();
        $token = $this->loginCustomer($user);
        [$api] = $this->orderOf($token, $user, 'demo-shami-grill', 'Hummus', 1);
        $line = $api['items'][0];

        $this->assertNull($line['offer']);
        $this->assertSame('0.00', $line['offer_discount']);
        $this->assertSame($line['line_subtotal'], $line['line_total']);
    }
}
