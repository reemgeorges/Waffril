<?php

namespace Tests\Feature\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PlusCycle;
use App\Models\PlusPlan;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Order\OrderAdminService;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Support\Str;

/**
 * WAFFRLI customer API (2026-11-03) — carts, checkout, orders, wallet and
 * PLUS on the seeded demo data.
 */
class CustomerCommerceTest extends CustomerTestCase
{
    protected function shami(string $branchEn = 'Mazzeh branch'): Branch
    {
        return Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-shami-grill'))->where('name->en', $branchEn)->firstOrFail();
    }

    protected function product(string $en): Product
    {
        return Product::where('name->en', $en)->firstOrFail();
    }

    protected function option(Product $product, string $en): int
    {
        return ProductOption::where('product_id', $product->id)->where('name->en', $en)->valueOrFail('id');
    }

    protected function addShawarma(string $token, int $qty = 2, string $bread = 'Saj bread'): array
    {
        $p = $this->product('Chicken shawarma wrap');

        return $this->api($token)->postJson('/api/v1/customer/cart/items', [
            'branch_id' => $this->shami()->id, 'product_id' => $p->id, 'quantity' => $qty,
            'options' => [['id' => $this->option($p, $bread)], ['id' => $this->option($p, 'Garlic sauce')]],
        ])->assertOk()->json('data.cart');
    }

    protected function checkout(string $token, int $cartId, array $body = [], ?string $key = null)
    {
        return $this->api($token)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson("/api/v1/customer/carts/{$cartId}/checkout", $body);
    }

    public function test_cart_validation_and_merging(): void
    {
        $user = $this->demoCustomer(6);
        $token = $this->loginCustomer($user);
        $p = $this->product('Chicken shawarma wrap');

        // Missing the required "Bread" choice.
        $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami()->id, 'product_id' => $p->id, 'quantity' => 1])->assertStatus(422);
        // Option of another product.
        $other = $this->product('Margherita pizza');
        $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami()->id, 'product_id' => $p->id, 'quantity' => 1, 'options' => [['id' => $this->option($other, 'Large')]]])->assertStatus(422);
        // Fries are unavailable at the Old City branch.
        $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami('Old City branch')->id, 'product_id' => $p->id, 'quantity' => 1, 'options' => [['id' => $this->option($p, 'Saj bread')], ['id' => $this->option($p, 'Fries')]]])->assertStatus(422);

        $cart = $this->addShawarma($token, 1);
        $cart = $this->addShawarma($token, 2);
        $this->assertCount(1, $cart['items'], 'Same configuration merges into one line.');
        $this->assertSame(3, $cart['items'][0]['quantity']);
        $cart = $this->addShawarma($token, 1, 'Tannour bread');
        $this->assertCount(2, $cart['items']);
        $this->assertNotNull($cart['preview']['total']);

        $this->api($token)->patchJson("/api/v1/customer/cart/items/{$cart['items'][0]['id']}", ['quantity' => 5])->assertOk();
        $this->api($token)->deleteJson("/api/v1/customer/cart/items/{$cart['items'][1]['id']}")->assertOk()->assertJsonCount(1, 'data.cart.items');

        // Another customer cannot see or change it.
        $intruder = $this->loginCustomer($this->demoCustomer(2), 'other');
        $this->api($intruder)->getJson("/api/v1/customer/carts/{$cart['id']}")->assertStatus(404);
        $this->api($intruder)->patchJson("/api/v1/customer/cart/items/{$cart['items'][0]['id']}", ['quantity' => 1])->assertStatus(404);

        // Aleppo customer cannot fill a Damascus cart.
        $aleppo = $this->loginCustomer($this->demoCustomer(9), 'aleppo');
        $this->api($aleppo)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami()->id, 'product_id' => $p->id, 'quantity' => 1, 'options' => [['id' => $this->option($p, 'Saj bread')]]])->assertStatus(404);
    }

    public function test_checkout_is_idempotent_and_server_priced(): void
    {
        $user = $this->demoCustomer(6);
        $token = $this->loginCustomer($user);
        $cart = $this->addShawarma($token, 2);
        $key = (string) Str::uuid();

        $this->api($token)->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertStatus(422); // no key
        $first = $this->checkout($token, $cart['id'], ['total' => '0.01', 'subtotal' => '0'], $key)->assertOk()->assertJsonPath('data.replayed', false);
        $replay = $this->checkout($token, $cart['id'], [], $key)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.order.id'), $replay->json('data.order.id'));
        $this->assertSame(1, Order::where('user_id', $user->id)->where('client_request_id', $key)->count());
        $this->assertNull(Cart::find($cart['id']), 'The cart is consumed.');

        $order = Order::findOrFail($first->json('data.order.id'));
        $this->assertNotSame('0.0100', (string) $order->total, 'Client totals are ignored.');
        $lines = $order->items->reduce(fn ($c, $i) => $c->plus((string) $i->line_total), BigDecimal::zero());
        $this->assertTrue(BigDecimal::of((string) $order->total)->isEqualTo($lines->minus((string) $order->plus_discount_amount)->plus((string) $order->delivery_fee)));
        $this->assertSame(2, $order->items->first()->options->count(), 'Option snapshots.');
        $this->assertSame((string) $order->total, (string) OrderPayment::where('order_id', $order->id)->sum('amount') + 0 == (float) $order->total ? (string) $order->total : 'mismatch');
        $this->assertSame('pending', $order->status->value);
        $this->assertSame(1, $order->statusHistories()->whereNull('from_status')->count());
    }

    public function test_wallet_partial_full_and_insufficient(): void
    {
        $user = $this->demoCustomer(1); // demo wallet: 2.00 USD (refund)
        $token = $this->loginCustomer($user);
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $wallet = Wallet::where('user_id', $user->id)->where('currency_id', $usd->id)->firstOrFail();
        $this->assertSame('2.0000', (string) $wallet->balance);

        $cart = $this->addShawarma($token, 1);
        $this->checkout($token, $cart['id'], ['wallet_amount' => '5.00', 'currency_id' => $usd->id])->assertStatus(422);
        $this->assertSame('2.0000', (string) $wallet->fresh()->balance, 'Nothing debited on failure.');

        $partial = $this->checkout($token, $cart['id'], ['wallet_amount' => '1.50', 'currency_id' => $usd->id])->assertOk()->json('data.order');
        $this->assertSame('0.5000', (string) $wallet->fresh()->balance);
        $methods = collect($partial['payments'])->keyBy('method');
        $this->assertSame('1.50', $methods['wallet']['amount']);
        $this->assertSame('paid', $methods['wallet']['status']);
        $this->assertSame('pending', $methods['cash']['status']);

        // Cancelling the pending order reverses the wallet part through the ledger.
        $this->api($token)->postJson("/api/v1/customer/orders/{$partial['id']}/cancel")->assertOk()->assertJsonPath('data.order.status', 'cancelled');
        $this->assertSame('2.0000', (string) $wallet->fresh()->balance);
        $this->assertTrue(app(WalletLedger::class)->isConsistent($wallet->fresh()));

        // Full wallet payment ("max" covers the whole small order when the balance allows).
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami()->id, 'product_id' => $this->product('Ayran')->id, 'quantity' => 1])->json('data.cart');
        $total = $cart['preview']['total'];
        $this->assertTrue(BigDecimal::of($total)->isLessThanOrEqualTo('2.00'), 'Ayran + delivery fits in the 2.00 wallet.');
        $full = $this->checkout($token, $cart['id'], ['wallet_amount' => 'max', 'currency_id' => $usd->id])->assertOk()->json('data.order');
        $this->assertSame(['wallet'], array_column($full['payments'], 'method'));
        $this->assertSame(Money::toStorage(BigDecimal::of('2.00')->minus($total)), (string) $wallet->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('direction', 'credit')->where('type', 'manual')->count());
    }

    public function test_plus_discount_has_priority_over_offers_and_combines_with_free_delivery(): void
    {
        $user = $this->demoCustomer(1); // Gold: 10% at Shami Grill (max 3.00), free deliveries
        $token = $this->loginCustomer($user);
        $cycle = PlusCycle::where('user_id', $user->id)->where('status', 'active')->firstOrFail();
        $usedBefore = $cycle->free_deliveries_used;
        $grill = $this->product('Mixed grill platter'); // has a 15% public offer

        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $this->shami()->id, 'product_id' => $grill->id, 'quantity' => 1, 'options' => [['id' => $this->option($grill, 'Large')]]])->json('data.cart');
        $order = $this->checkout($token, $cart['id'], ['use_free_delivery' => true])->assertOk()->json('data.order');

        $this->assertSame('0.00', $order['amounts']['offer_discount'], 'Public offer is not applied when PLUS applies.');
        $this->assertNotSame('0.00', $order['amounts']['plus_discount']);
        $this->assertTrue($order['amounts']['free_delivery_applied']);
        $this->assertSame('0.00', $order['amounts']['delivery_fee']);
        $this->assertSame($usedBefore + 1, $cycle->fresh()->free_deliveries_used);

        // Allowance exhausted: free delivery is simply not applied any more.
        $cycle->forceFill(['free_deliveries_used' => $cycle->free_deliveries_total])->save();
        $cart = $this->addShawarma($token, 1);
        $next = $this->checkout($token, $cart['id'], ['use_free_delivery' => true])->assertOk()->json('data.order');
        $this->assertFalse($next['amounts']['free_delivery_applied']);
        $this->assertSame($cycle->free_deliveries_total, $cycle->fresh()->free_deliveries_used);
    }

    public function test_scheduled_checkout_window_and_currency_freeze(): void
    {
        $user = $this->demoCustomer(6);
        $token = $this->loginCustomer($user);
        $syp = Currency::where('code', 'SYP')->firstOrFail();

        $cart = $this->addShawarma($token, 1);
        $this->checkout($token, $cart['id'], ['scheduled_for' => now()->addMinutes(10)->toIso8601String()])->assertStatus(422);
        $this->checkout($token, $cart['id'], ['scheduled_for' => now()->addDays(10)->toIso8601String()])->assertStatus(422);
        $order = $this->checkout($token, $cart['id'], ['scheduled_for' => now()->addHours(3)->toIso8601String(), 'currency_id' => $syp->id])->assertOk()->json('data.order');

        $this->assertSame('scheduled', $order['type']);
        $this->assertSame('SYP', $order['currency']['code']);
        $this->assertStringNotContainsString('.', $order['total'], 'SYP has 0 decimals.');
        $model = Order::findOrFail($order['id']);
        $this->assertSame('13000.000000000000', (string) $model->exchange_rate);
        // Accepting before the window opens is refused by the admin service.
        $admin = Admin::factory()->create();
        $this->seed(AdminAccessSeeder::class);
        $admin->assignRole('super-admin');
        $this->expectException(BusinessRuleException::class);
        app(OrderAdminService::class)->accept($model, $admin);
    }

    public function test_orders_ownership_cancellation_rules_and_history(): void
    {
        $user = $this->demoCustomer(2);
        $token = $this->loginCustomer($user);

        $current = $this->api($token)->getJson('/api/v1/customer/orders?scope=current')->assertOk()->json('data.orders');
        $this->assertTrue(collect($current)->every(fn ($o) => in_array($o['status'], ['pending', 'accepted', 'assigned', 'picked_up'], true)));
        $previous = $this->api($token)->getJson('/api/v1/customer/orders?scope=previous')->assertOk()->json('data.orders');
        $this->assertTrue(collect($previous)->every(fn ($o) => in_array($o['status'], ['delivered', 'cancelled'], true)));

        $foreign = Order::where('user_id', '!=', $user->id)->firstOrFail();
        $this->api($token)->getJson("/api/v1/customer/orders/{$foreign->id}")->assertStatus(404);
        $this->api($token)->postJson("/api/v1/customer/orders/{$foreign->id}/cancel")->assertStatus(404);

        // DEMO-0029 is accepted with a pending request already; DEMO-0022 is delivered.
        $accepted = Order::where('order_number', 'DEMO-0029')->firstOrFail();
        $this->api($token)->postJson("/api/v1/customer/orders/{$accepted->id}/cancel")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/customer/orders/{$accepted->id}/cancellation-request", ['note' => 'again'])->assertStatus(409);
        $delivered = Order::where('order_number', 'DEMO-0022')->firstOrFail();
        $this->api($token)->postJson("/api/v1/customer/orders/{$delivered->id}/cancellation-request")->assertStatus(409);

        $detail = $this->api($token)->getJson("/api/v1/customer/orders/{$accepted->id}")->assertOk()->json('data.order');
        $this->assertNotEmpty($detail['status_history']);
        $this->assertFalse($detail['can_cancel']);
        $this->assertFalse($detail['can_request_cancellation']);
        $this->assertSame('order.'.$accepted->id, $detail['realtime_channel']);

        // Assigned order: driver first name + vehicle only, no phone.
        $assignedOrder = Order::where('order_number', 'DEMO-0032')->firstOrFail();
        $mine = $this->api($this->loginCustomer(User::findOrFail($assignedOrder->user_id), 'x'))->getJson("/api/v1/customer/orders/{$assignedOrder->id}")->json('data.order.driver');
        $this->assertSame(['first_name', 'vehicle_type'], array_keys($mine));
    }

    public function test_cancellation_request_for_an_accepted_order(): void
    {
        $user = $this->demoCustomer(6);
        $order = Order::where('order_number', 'DEMO-0030')->firstOrFail(); // accepted, scheduled, customer 6
        $token = $this->loginCustomer($user);
        $this->api($token)->postJson("/api/v1/customer/orders/{$order->id}/cancellation-request", ['note' => 'Change of plans'])->assertOk()->assertJsonPath('data.request.status', 'pending');
        $this->api($token)->postJson("/api/v1/customer/orders/{$order->id}/cancellation-request")->assertStatus(409);
        $this->assertSame('accepted', $order->fresh()->status->value, 'The order is not cancelled by the customer.');
    }

    public function test_wallet_payments_refunds_and_currencies_are_read_only_views(): void
    {
        $user = $this->demoCustomer(9); // SYP wallet from a refund
        $token = $this->loginCustomer($user);
        $wallets = $this->api($token)->getJson('/api/v1/customer/wallet')->assertOk()->json('data.wallets');
        $this->assertSame('SYP', $wallets[0]['currency']);
        $this->assertSame('5000', $wallets[0]['balance']);

        $tx = $this->api($token)->getJson('/api/v1/customer/wallet/transactions?currency=SYP')->assertOk()->json('data.transactions');
        $this->assertNotEmpty($tx);
        $this->assertNotEmpty($this->api($token)->getJson('/api/v1/customer/refunds')->assertOk()->json('data.refunds'));
        $this->assertNotEmpty($this->api($token)->getJson('/api/v1/customer/payments')->assertOk()->json('data.payments'));
        $this->assertCount(3, $this->api($token)->getJson('/api/v1/customer/currencies')->assertOk()->json('data.currencies'));
    }

    public function test_plus_customer_endpoints(): void
    {
        $gold = $this->loginCustomer($this->demoCustomer(1));
        $this->assertCount(3, $this->api($gold)->getJson('/api/v1/customer/plus/plans')->assertOk()->json('data.plans'));
        $m = $this->api($gold)->getJson('/api/v1/customer/plus/membership')->assertOk()->json('data.membership');
        $this->assertSame('active', $m['current_cycle']['status']);
        $this->assertSame('scheduled', $m['next_cycle']['status']);
        $this->assertSame(6, $m['next_cycle']['free_deliveries_total'], 'Snapshot of the next cycle includes the approved override.');
        $this->assertNotEmpty($this->api($gold)->getJson('/api/v1/customer/plus/eligible-discounts?branch_id='.$this->shami()->id)->json('data.discounts'));

        // Customer 1 already has a pending change request; a duplicate is refused.
        $family = PlusPlan::where('code', 'demo-plus-family')->firstOrFail();
        $this->api($gold)->postJson('/api/v1/customer/plus/requests', ['type' => 'change', 'plan_id' => $family->id])->assertStatus(409);
        $this->api($gold)->postJson('/api/v1/customer/plus/requests', ['type' => 'new', 'plan_id' => $family->id])->assertStatus(422); // already a member
        $this->api($gold)->postJson('/api/v1/customer/plus/requests', ['type' => 'renew'])->assertOk();

        $none = $this->loginCustomer($this->demoCustomer(10), 'n');
        $this->api($none)->getJson('/api/v1/customer/plus/membership')->assertOk()->assertJsonPath('data.membership', null);
        $this->api($none)->postJson('/api/v1/customer/plus/requests', ['type' => 'cancel'])->assertStatus(422);
        $this->api($none)->postJson('/api/v1/customer/plus/requests', ['type' => 'custom'])->assertStatus(422);
        $this->api($none)->postJson('/api/v1/customer/plus/requests', ['type' => 'new', 'plan_id' => $family->id])->assertOk()->assertJsonPath('data.request.status', 'pending');
        $this->assertCount(1, $this->api($none)->getJson('/api/v1/customer/plus/requests')->json('data.requests'));
    }
}
