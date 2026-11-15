<?php

namespace Tests\Feature\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\Order;
use App\Models\PlusCycle;
use App\Models\PlusPlan;
use App\Models\User;
use App\Services\Currency\CurrencyPolicy;
use App\Services\Customer\CustomerCatalogService;
use App\Services\Driver\DriverCashSettlementService;
use App\Services\Driver\DriverDeliveryService;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Services\Order\OrderAdminService;
use App\Services\Order\OrderPricingService;
use App\Services\Order\RefundService;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SypOperationalCurrencyTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['waffrli.currency.customer_currencies' => ['SYP'], 'waffrli.currency.operational_default' => 'SYP']);
    }

    public function test_policy_keeps_accounting_currency_and_ignores_disabled_preferences(): void
    {
        $user = $this->demoCustomer(1);
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $user->update(['display_currency_id' => $usd->id]);
        $this->assertSame(['SYP'], CurrencyPolicy::customerCurrencies()->pluck('code')->all());
        $this->assertSame('SYP', CurrencyPolicy::paymentCurrency($user, null)->code);
        $this->assertSame('USD', Currency::query()->default()->firstOrFail()->code);
        $this->assertFalse(CurrencyPolicy::customerCanChoose());
        $this->expectException(BusinessRuleException::class);
        CurrencyPolicy::paymentCurrency($user, $usd->id);
    }

    public function test_catalog_converts_real_amount_and_missing_rate_fails_closed(): void
    {
        $source = Currency::query()->default()->firstOrFail();
        $target = Currency::where('code', 'SYP')->firstOrFail();
        $price = app(CustomerCatalogService::class)->price('2.50', $this->demoCustomer(1));
        $this->assertSame('SYP', $price['currency']);
        // 2.50 USD × 13000 = 32,500 old SYP, shown as 325.00 new SYP (converted once).
        $this->assertSame(Denomination::presentString(app(CurrencyConverter::class)->convert('2.50', $source, $target), $target), $price['amount']);
        $this->assertSame('325.00', $price['amount']);
        $this->assertNotSame('2.50', $price['amount']);
        $target->update(['exchange_rate' => '0']);
        $this->expectException(BusinessRuleException::class);
        app(CustomerCatalogService::class)->price('2.50', $this->demoCustomer(1));
    }

    public function test_unavailable_syp_does_not_fall_back_to_usd(): void
    {
        Currency::where('code', 'SYP')->update(['is_active' => false]);
        $this->assertNull(CurrencyPolicy::displayCurrency($this->demoCustomer(1)));
        $this->expectException(BusinessRuleException::class);
        CurrencyPolicy::paymentCurrency($this->demoCustomer(1), null);
    }

    public function test_cart_checkout_payments_and_history_are_consistent(): void
    {
        $history = DB::table('orders')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $user = $this->demoCustomer(1);
        $token = $this->loginCustomer($user);
        $cityId = $user->addresses()->where('is_default', true)->firstOrFail()->city_id;
        $product = BranchProduct::where('is_available', true)
            ->whereHas('branch', fn ($query) => $query->where('city_id', $cityId)->whereHas('store', fn ($store) => $store->where('slug', 'demo-fresh-market')))
            ->whereDoesntHave('product.optionGroups', fn ($query) => $query->where('min_select', '>', 0))->firstOrFail();
        $cycles = DB::table('plus_cycles')->pluck('free_deliveries_used', 'id')->all();
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', [
            'branch_id' => $product->branch_id, 'product_id' => $product->product_id, 'quantity' => 3,
        ])->assertOk()->json('data.cart');
        $this->assertSame('SYP', $cart['preview']['currency']);
        $this->assertSame($cycles, DB::table('plus_cycles')->pluck('free_deliveries_used', 'id')->all());
        $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['currency_id' => Currency::where('code', 'USD')->value('id')])->assertStatus(422);
        $orderData = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');
        $this->assertSame('SYP', $orderData['currency']['code']);
        $this->assertTrue(Money::of($cart['preview']['total'])->isEqualTo($orderData['amounts']['total']));
        $order = Order::findOrFail($orderData['id']);
        $this->assertTrue(Money::of($order->subtotal)->minus($order->offer_discount_total)->minus($order->plus_discount_amount)->plus($order->delivery_fee)->isEqualTo($order->total));
        $this->assertTrue(Money::of((string) $order->payments()->sum('amount'))->isEqualTo($order->total));
        $this->assertSame([$order->currency_id], $order->payments()->pluck('currency_id')->unique()->all());
        $this->assertSame($history, DB::table('orders')->where('id', '!=', $order->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);
        $driver = Driver::create([
            'name' => 'SYP test driver', 'phone' => '+963944123456', 'password' => Str::random(32),
            'city_id' => $cityId, 'availability' => Driver::AVAILABILITY_AVAILABLE,
        ])->fresh();
        $adminOrders = app(OrderAdminService::class);
        $adminOrders->accept($order, $admin);
        $adminOrders->assignDriver($order, $driver, $admin);
        $deliveries = app(DriverDeliveryService::class);
        $this->assertTrue($deliveries::cashDue($order)->isEqualTo($order->total));
        $deliveries->pickUp($driver, $order->id);
        $deliveries->deliver($driver, $order->id, $order->total, 'SYP', null);
        $payment = $order->payments()->whereHas('method', fn ($query) => $query->where('kind', 'cash'))->firstOrFail();
        $this->assertTrue(Money::of($payment->collected_amount)->isEqualTo($order->total));
        $settlements = app(DriverCashSettlementService::class);
        $settlement = $settlements->prepare($driver, $order->currency, now(PlatformSettings::timezone())->toDateString(), $admin);
        $this->assertTrue(Money::of($settlement->expected_amount)->isEqualTo($order->total));
        $settlements->settle($settlement, $order->total, null, $admin);
        $refund = app(RefundService::class)->refund($payment, '100', 'syp_test', null, $admin, (string) Str::uuid());
        $this->assertSame($order->currency_id, $refund->currency_id);
        $this->assertTrue(Money::of($refund->amount)->isEqualTo('100'));
    }

    /** A cart in the customer's city with one product that needs no options. */
    protected function cartFor(User $user, string $token): array
    {
        $cityId = $user->addresses()->where('is_default', true)->firstOrFail()->city_id;
        $product = BranchProduct::where('is_available', true)
            ->whereHas('branch', fn ($query) => $query->where('city_id', $cityId)->where('is_active', true))
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->whereDoesntHave('product.optionGroups', fn ($query) => $query->where('min_select', '>', 0))->firstOrFail();

        return $this->api($token)->postJson('/api/v1/customer/cart/items', [
            'branch_id' => $product->branch_id, 'product_id' => $product->product_id, 'quantity' => 3,
        ])->assertOk()->json('data.cart');
    }

    public function test_wallet_part_stays_in_whole_syp_so_cash_due_is_collectable(): void
    {
        $user = $this->demoCustomer(9); // SYP wallet from a refund
        $token = $this->loginCustomer($user);
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        // A fractional balance (e.g. from an old 4-decimal record).
        DB::table('wallets')->where('user_id', $user->id)->where('currency_id', $syp->id)->update(['balance' => '1000.5000']);
        $cart = $this->cartFor($user, $token);

        // Typed in NEW SYP: a third decimal (below one qirsh = one old pound) is refused.
        $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['wallet_amount' => '10.555'])->assertStatus(422);

        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['wallet_amount' => 'max'])->assertOk()->json('data.order');
        $payments = Order::findOrFail($order['id'])->payments()->with('method')->get();
        $wallet = $payments->firstWhere('method.kind', '!=', 'cash');
        $cash = $payments->firstWhere('method.kind', 'cash');
        $this->assertTrue(Money::of($wallet->amount)->isEqualTo('1000'), 'whole stored (old) pounds only from the wallet');
        $this->assertSame('10.00', collect($order['payments'])->firstWhere('method', 'wallet')['amount'], '1,000 old SYP = 10 new SYP');
        $this->assertTrue(Money::of($cash->amount)->isEqualTo(Money::roundTo($cash->amount, 0)), 'cash due has no fraction');
        $this->assertTrue(Denomination::present(Money::of($wallet->amount)->plus($cash->amount), $syp)->isEqualTo($order['amounts']['total']));
    }

    public function test_a_typed_wallet_amount_is_new_syp_converted_once(): void
    {
        $user = $this->demoCustomer(9); // 5,000 old SYP = 50 new SYP in the wallet
        $token = $this->loginCustomer($user);
        $cart = $this->cartFor($user, $token);

        // 10.50 new SYP = 1,050 old SYP stored.
        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['wallet_amount' => '10.50'])->assertOk()->json('data.order');
        $wallet = Order::findOrFail($order['id'])->payments()->whereHas('method', fn ($q) => $q->where('kind', '!=', 'cash'))->sole();
        $this->assertTrue(Money::of($wallet->amount)->isEqualTo('1050'));
        $this->assertSame('10.50', collect($order['payments'])->firstWhere('method', 'wallet')['amount']);

        // The stored (old) number typed as new SYP is 100× more and is refused, not silently divided.
        $cart = $this->cartFor($user, $token);
        $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['wallet_amount' => '3950'])->assertStatus(422);
    }

    public function test_plus_cycle_amounts_recorded_in_syp_are_not_converted_again(): void
    {
        $user = $this->demoCustomer(1); // demo PLUS member with an active cycle
        $cycle = PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)->firstOrFail();
        $rule = $cycle->discounts()->firstOrFail();
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        // Test-only: a fixed cycle benefit whose operational value is already in SYP.
        DB::table('plus_cycle_discounts')->where('id', $rule->id)->update([
            'discount_type' => 'fixed', 'op_currency_id' => $syp->id, 'op_discount_value' => '1000',
            'op_min_order_amount' => null, 'op_max_discount_amount' => null, 'max_uses_per_cycle' => null,
        ]);
        $branch = Branch::query()->where('store_id', $rule->store_id)->whereHas('branchProducts', fn ($q) => $q->where('is_available', true))->firstOrFail();
        $line = BranchProduct::query()->where('branch_id', $branch->id)->where('is_available', true)
            ->whereDoesntHave('product.optionGroups', fn ($q) => $q->where('min_select', '>', 0))->firstOrFail();

        $priced = app(OrderPricingService::class)->price($user, $branch, [['branch_product_id' => $line->id, 'quantity' => 5]], $syp, $user->addresses()->where('is_default', true)->first(), false);

        $this->assertTrue($priced['subtotal']->isGreaterThan(1000));
        $this->assertTrue($priced['plus_discount_amount']->isEqualTo('1000'), 'SYP benefit applied as 1,000 SYP, not converted a second time');
    }

    public function test_api_exposes_only_syp_and_converts_plus_prices(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $this->api($token)->getJson('/api/v1/customer/currencies')->assertOk()
            ->assertJsonCount(1, 'data.currencies')->assertJsonPath('data.currencies.0.code', 'SYP')
            ->assertJsonPath('data.currencies.0.is_default', true);
        $plans = $this->api($token)->getJson('/api/v1/customer/plus/plans')->assertOk()->json('data.plans');
        $this->assertNotEmpty($plans);
        foreach ($plans as $plan) {
            $original = PlusPlan::findOrFail($plan['id']);
            $this->assertSame('SYP', $plan['currency']);
            $this->assertSame(CurrencyPolicy::operationalAmount($original->display_price), $plan['display_price']);
        }
    }
}
