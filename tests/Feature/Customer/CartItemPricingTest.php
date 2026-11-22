<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductOption;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-22) — cart items expose the server's own per-line price
 * (same OrderPricingService call as the cart preview and checkout), and the
 * placed order returns the same numbers. Additive: every older field stays.
 */
class CartItemPricingTest extends CustomerTestCase
{
    private function shami(): Branch
    {
        return Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-shami-grill'))->where('name->en', 'Mazzeh branch')->firstOrFail();
    }

    private function option(Product $product, string $en): int
    {
        return ProductOption::where('product_id', $product->id)->where('name->en', $en)->valueOrFail('id');
    }

    private function add(string $token, string $productEn, int $qty, array $optionsEn): array
    {
        $product = Product::where('name->en', $productEn)->firstOrFail();

        return $this->api($token)->postJson('/api/v1/customer/cart/items', [
            'branch_id' => $this->shami()->id, 'product_id' => $product->id, 'quantity' => $qty,
            'options' => array_map(fn ($o) => ['id' => $this->option($product, $o)], $optionsEn),
        ])->assertOk()->json('data.cart');
    }

    private function d(?string $v): BigDecimal
    {
        $this->assertNotNull($v);

        return BigDecimal::of($v);
    }

    private function sum(array $items, string $field): BigDecimal
    {
        return array_reduce($items, fn (BigDecimal $c, $i) => $c->plus($i[$field]), BigDecimal::zero());
    }

    public function test_cart_items_carry_server_prices_that_add_up_and_match_the_order(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(6));   // no PLUS
        $this->add($token, 'Chicken shawarma wrap', 2, ['Saj bread', 'Garlic sauce']);
        $cart = $this->add($token, 'Mixed grill platter', 1, ['Large']);   // has a 15% public offer

        $this->assertNull($cart['issue']);
        $currency = $cart['preview']['currency'];
        foreach ($cart['items'] as $item) {
            // Older fields unchanged.
            foreach (['id', 'product_id', 'name', 'image_url', 'quantity', 'notes', 'is_available', 'options'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
            $this->assertSame($currency, $item['currency']);
            $this->assertIsString($item['unit_price']);
            $options = array_reduce($item['options'], fn (BigDecimal $c, $o) => $c->plus($this->d($o['total_price_delta'])), BigDecimal::zero());
            foreach ($item['options'] as $o) {
                $this->assertTrue($this->d($o['unit_price_delta'])->multipliedBy($o['quantity'])->isEqualTo($o['total_price_delta']));
            }
            $this->assertTrue($this->d($item['options_unit_price'])->isEqualTo($options), 'options_unit_price = Σ option totals');
            $this->assertTrue($this->d($item['unit_price'])->isEqualTo($this->d($item['base_unit_price'])->plus($item['options_unit_price'])));
            $this->assertTrue($this->d($item['line_subtotal'])->isEqualTo($this->d($item['unit_price'])->multipliedBy($item['quantity'])));
            $this->assertTrue($this->d($item['line_total'])->isEqualTo($this->d($item['line_subtotal'])->minus($item['offer_discount'])));
        }
        $grillId = Product::where('name->en', 'Mixed grill platter')->value('id');
        $grill = collect($cart['items'])->firstWhere('product_id', $grillId);
        $this->assertTrue($this->d($grill['offer_discount'])->isPositive(), 'The public offer is per line.');

        // The cart preview is the sum of its lines.
        $this->assertTrue($this->d($cart['preview']['subtotal'])->isEqualTo($this->sum($cart['items'], 'line_subtotal')));
        $this->assertTrue($this->d($cart['preview']['offer_discount'])->isEqualTo($this->sum($cart['items'], 'offer_discount')));
        $this->assertTrue($this->d($cart['preview']['total'])->isEqualTo(
            $this->sum($cart['items'], 'line_total')->minus($cart['preview']['plus_discount'])->plus($cart['preview']['delivery_fee'])));

        // Checkout charges exactly these numbers.
        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');
        $this->assertSame($cart['preview']['total'], $order['amounts']['total']);
        foreach ($cart['items'] as $item) {
            $placed = collect($order['items'])->first(fn ($i) => $i['product_id'] === $item['product_id']);
            foreach (['base_unit_price', 'options_unit_price', 'unit_price', 'line_subtotal', 'offer_discount', 'line_total'] as $field) {
                $this->assertSame($item[$field], $placed[$field], "order item {$field}");
            }
            $this->assertSame(array_column($item['options'], 'unit_price_delta'), array_column($placed['options'], 'unit_price_delta'));
        }
    }

    public function test_plus_discount_is_cart_level_and_lines_carry_no_offer_when_plus_applies(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));   // Gold: 10% at Shami Grill
        $cart = $this->add($token, 'Mixed grill platter', 1, ['Large']);

        $this->assertTrue($this->d($cart['preview']['plus_discount'])->isPositive());
        $item = $cart['items'][0];
        $this->assertTrue($this->d($item['offer_discount'])->isZero(), 'PLUS has priority over public offers.');
        $this->assertSame($item['line_subtotal'], $item['line_total']);
        $this->assertTrue($this->d($cart['preview']['total'])->isEqualTo(
            $this->d($item['line_total'])->minus($cart['preview']['plus_discount'])->plus($cart['preview']['delivery_fee'])));
    }

    public function test_line_prices_are_null_when_the_cart_cannot_be_priced(): void
    {
        $user = $this->demoCustomer(6);
        $token = $this->loginCustomer($user);
        $cart = $this->add($token, 'Chicken shawarma wrap', 1, ['Saj bread']);
        // The product becomes unavailable: the server refuses to price the cart.
        BranchProduct::query()->where('branch_id', $this->shami()->id)
            ->where('product_id', $cart['items'][0]['product_id'])->update(['is_available' => false]);

        $cart = $this->api($token)->getJson('/api/v1/customer/carts')->assertOk()->json('data.carts.0');
        $this->assertNull($cart['preview']);
        $this->assertNotNull($cart['issue']);
        $this->assertNull($cart['items'][0]['unit_price']);
        $this->assertNull($cart['items'][0]['line_total']);
        $this->assertNull($cart['items'][0]['options'][0]['unit_price_delta']);
        $this->assertSame(1, $cart['items'][0]['quantity']);
    }
}
