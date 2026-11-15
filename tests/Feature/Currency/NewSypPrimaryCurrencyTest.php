<?php

namespace Tests\Feature\Currency;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\BranchPricesRelationManager;
use App\Models\Admin;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Services\Currency\CurrencyRedenominationService;
use App\Services\Currency\OperationalMoney;
use App\Services\Functional\Currency\CurrencyConverter;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Database\Seeders\AdminAccessSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * NEW SYP as the ACTUAL primary currency (2026-11-15): after the audited
 * default-currency switch (CurrencyRedenominationService) SYP is the base.
 * Its row keeps storing OLD pounds (1 old pound = 1 qirsh), so every new
 * pound amount an admin types is stored exactly, the APIs show new pounds,
 * history keeps its own currency and other currencies stay available.
 *
 * Runs on the seeded demo data in the isolated test database only. The
 * rate used here (the seeded 13000 old = 130 new per USD) is the DEMO rate.
 */
class NewSypPrimaryCurrencyTest extends CustomerTestCase
{
    /** History tables that a switch must leave byte-for-byte identical. */
    private const HISTORY = ['orders', 'order_items', 'order_item_options', 'order_payments', 'order_refunds', 'wallets', 'wallet_transactions', 'driver_cash_settlements', 'plus_cycles'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'waffrli.currency.customer_currencies' => ['SYP'],
            'waffrli.currency.operational_default' => 'SYP',
            'waffrli.currency.approved_operational_rate' => null,
            'waffrli.currency.redenomination_enabled' => true,
        ]);
        (new AdminAccessSeeder)->run();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        OperationalMoney::flush();
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    protected function history(): array
    {
        return collect(self::HISTORY)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    protected function stored($model, string $field): string
    {
        return Money::toStorage((string) $model->getRawOriginal($field));
    }

    public function test_the_rate_is_typed_in_new_pounds_and_stored_in_old_pounds(): void
    {
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $this->actingAs($this->superAdmin(), 'admin_web');
        $page = fn () => Livewire::test(ManageCurrencies::class);

        $page()->mountAction(TestAction::make('edit')->table($syp))->assertSchemaStateSet(['exchange_rate' => '130']);
        $page()->callAction(TestAction::make('edit')->table($syp), ['exchange_rate' => '131.5'])->assertHasNoFormErrors();
        $this->assertTrue(Money::of((string) $syp->fresh()->exchange_rate)->isEqualTo('13150'), '131.5 new = 13,150 old');
        $page()->callAction(TestAction::make('edit')->table($syp), ['exchange_rate' => '130'])->assertHasNoFormErrors();
        $this->assertTrue(Money::of((string) $syp->fresh()->exchange_rate)->isEqualTo('13000'));
        // The stored unit's decimals cannot be changed by mistake.
        $page()->callAction(TestAction::make('edit')->table($syp), ['decimals' => 2])->assertHasNoFormErrors();
        $this->assertSame(0, (int) $syp->fresh()->decimals);
    }

    public function test_after_the_switch_syp_is_the_primary_currency_end_to_end(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $shawarma = BranchProduct::query()->whereHas('product', fn ($p) => $p->where('name->en', 'Chicken shawarma wrap'))->orderBy('id')->firstOrFail();
        $this->assertTrue(Money::of((string) $shawarma->price)->isEqualTo('2.50'), 'stored in USD before the switch');
        $usdOrder = Order::query()->where('currency_id', $usd->id)->firstOrFail();
        $history = $this->history();

        // The audited, atomic switch (what Platform → Currency re-denominations runs).
        $admin = $this->superAdmin();
        $service = app(CurrencyRedenominationService::class);
        $run = $service->execute($service->request($admin, $syp->id, (string) Str::uuid()));
        $this->assertSame('completed', $run->fresh()->status);
        OperationalMoney::flush();
        // History is byte-for-byte untouched by the switch and keeps its currency.
        $this->assertSame($history, $this->history());

        // 1. SYP is the default; rates re-based; other currencies kept for the future.
        $this->assertSame('SYP', Currency::query()->default()->value('code'));
        $this->assertTrue(Money::of((string) $syp->fresh()->exchange_rate)->isEqualTo(1));
        $this->assertTrue(Money::of((string) $usd->fresh()->exchange_rate)->multipliedBy(13000)->toScale(6, RoundingMode::HALF_UP)->isEqualTo(1));
        $this->assertTrue($usd->fresh()->is_active, 'USD stays in the database for future use');
        $this->assertTrue(OperationalMoney::sameAsBase());
        $this->assertTrue(OperationalMoney::inputEnabled(), 'no separate approval: base = operational currency');

        // 2. The catalog is stored in SYP (old pounds = qirsh), shown in new pounds.
        $this->assertSame('32500.0000', $this->stored($shawarma->fresh(), 'price'));
        $token = $this->loginCustomer($this->demoCustomer(1));
        $api = $this->api($token)->getJson("/api/v1/customer/branches/{$shawarma->branch_id}/products/{$shawarma->product_id}")->assertOk()->json('data.product.price');
        $this->assertSame(['amount' => '325.00', 'currency' => 'SYP', 'display' => null], $api);

        // 3. Admins type NEW pounds directly; every qirsh amount is stored exactly.
        $this->actingAs($admin, 'admin_web');
        $product = Product::findOrFail($shawarma->product_id);
        $prices = fn () => Livewire::test(BranchPricesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $prices()->mountAction(TestAction::make('edit')->table($shawarma))->assertSchemaStateSet(['price' => '325.00']);
        $prices()->callAction(TestAction::make('edit')->table($shawarma), ['price' => '150.00'])->assertHasNoFormErrors();
        $this->assertSame('15000.0000', $this->stored($shawarma->fresh(), 'price'), '150.00 new = 15,000 old, exact (impossible with a USD base)');
        $prices()->callAction(TestAction::make('edit')->table($shawarma), ['price' => '1234.57'])->assertHasNoFormErrors();
        $this->assertSame('123457.0000', $this->stored($shawarma->fresh(), 'price'));
        $prices()->callAction(TestAction::make('edit')->table($shawarma), ['price' => '10.005'])->assertHasFormErrors(['price']);
        $this->assertSame('123457.0000', $this->stored($shawarma->fresh(), 'price'));

        // 4. A new order is priced and paid in SYP as the base (rate 1), shown in new pounds.
        $user = $this->demoCustomer(1);
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $shawarma->branch_id, 'product_id' => $shawarma->product_id, 'quantity' => 2,
            'options' => $product->optionGroups()->where('is_active', true)->where('min_select', '>', 0)->with('options')->get()
                ->map(fn ($g) => ['id' => $g->options->first()->id, 'quantity' => 1])->values()->all()])->assertOk()->json('data.cart');
        $o = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');
        $order = Order::findOrFail($o['id']);
        $this->assertSame($syp->id, (int) $order->currency_id);
        $this->assertSame($syp->id, (int) $order->base_currency_id);
        $this->assertTrue(Money::of((string) $order->exchange_rate)->isEqualTo(1));
        $this->assertSame(Denomination::presentString((string) $order->total, $syp), $o['amounts']['total']);
        $this->assertTrue(Money::of((string) $order->payments()->sum('amount'))->isEqualTo((string) $order->total));
        $this->assertSame($user->id, $order->user_id);

        // 5. Earlier orders are unchanged and a historical USD order stays USD.
        $this->assertSame($history['orders'], DB::table('orders')->where('id', '<', $order->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($usd->id, (int) $usdOrder->fresh()->currency_id);
        $this->assertSame((string) $usdOrder->total, (string) $usdOrder->fresh()->total);

        // 6. Future multi-currency: another currency still converts through the new base.
        $this->assertTrue(app(CurrencyConverter::class)->convert('32500', $syp->fresh(), $usd->fresh())->isEqualTo('2.50'));
    }
}
