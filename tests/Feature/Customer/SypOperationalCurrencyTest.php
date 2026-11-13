<?php

namespace Tests\Feature\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\Order;
use App\Models\PlusPlan;
use App\Services\Currency\CurrencyPolicy;
use App\Services\Customer\CustomerCatalogService;
use App\Services\Driver\DriverCashSettlementService;
use App\Services\Driver\DriverDeliveryService;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
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
        $this->assertSame((string) app(CurrencyConverter::class)->convert('2.50', $source, $target), $price['amount']);
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
