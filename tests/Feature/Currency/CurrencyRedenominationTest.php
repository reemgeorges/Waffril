<?php

namespace Tests\Feature\Currency;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CurrencyRedenominations\Pages\ListCurrencyRedenominations;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-03) — default-currency switch: permission + flag,
 * conversion with rounding, rate re-basing, untouched history, atomic
 * failure, idempotency and resumability (SQLite and MySQL 8).
 */
class CurrencyRedenominationTest extends CustomerTestCase
{
    /** History tables whose rows must be byte-for-byte identical after a switch. */
    private const HISTORY = ['orders', 'order_items', 'order_item_options', 'order_payments', 'order_refunds', 'wallets', 'wallet_transactions', 'driver_cash_settlements', 'plus_cycles'];

    protected function admin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    protected function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    protected function service(): CurrencyRedenominationService
    {
        return app(CurrencyRedenominationService::class);
    }

    public function test_switch_is_disabled_by_default_and_requires_permission(): void
    {
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        try {
            $this->service()->request($this->admin(), $syp->id, 'k-disabled');
            $this->fail('Disabled by default.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }

        config(['waffrli.currency.redenomination_enabled' => true]);
        $employee = Admin::factory()->create(['is_active' => true]);
        $employee->givePermissionTo('currencies.manage');
        try {
            $this->service()->request($employee, $syp->id, 'k-no-permission');
            $this->fail('currencies.change_default is required.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }
        $this->assertSame(0, CurrencyRedenomination::count());
    }

    public function test_switch_converts_the_catalog_rebases_rates_and_preserves_history(): void
    {
        config(['waffrli.currency.redenomination_enabled' => true]);
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $others = Currency::whereNotIn('id', [$usd->id, $syp->id])->get();
        $rate = (string) $syp->exchange_rate;
        $round = fn ($v) => $v === null ? null : Money::toStorage(BigDecimal::of((string) $v)->multipliedBy($rate)->toScale((int) $syp->decimals, RoundingMode::HALF_UP));

        $prices = DB::table('branch_products')->pluck('price', 'id');
        $deltas = DB::table('branch_product_options')->pluck('price_delta', 'id');
        $fees = DB::table('delivery_fee_rules')->get(['id', 'base_fee', 'per_km_fee'])->keyBy('id');
        $offers = DB::table('offers')->get(['id', 'discount_type', 'discount_value'])->keyBy('id');
        $cycleDiscounts = DB::table('plus_cycle_discounts')->get()->keyBy('id');
        $history = $this->snapshot(self::HISTORY);
        $this->assertNotEmpty($history['orders']);

        $run = $this->service()->request($this->admin(), $syp->id, 'switch-1');
        $this->assertSame($run->id, $this->service()->request($this->admin(), $syp->id, 'switch-1')->id, 'Same key, same run.');
        try {
            $this->service()->request($this->admin(), $syp->id, 'switch-2');
            $this->fail('Only one run may be in flight.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }

        $run = $this->service()->execute($run);
        $this->assertSame(CurrencyRedenomination::STATUS_COMPLETED, $run->status);

        // Catalog converted with HALF_UP rounding to SYP decimals.
        foreach (DB::table('branch_products')->pluck('price', 'id') as $id => $price) {
            $this->assertSame($round($prices[$id]), Money::toStorage((string) $price), "branch_products #{$id}");
        }
        foreach (DB::table('branch_product_options')->pluck('price_delta', 'id') as $id => $delta) {
            $this->assertSame($round($deltas[$id]), Money::toStorage((string) $delta));
        }
        foreach (DB::table('delivery_fee_rules')->get() as $rule) {
            $this->assertSame($round($fees[$rule->id]->base_fee), Money::toStorage((string) $rule->base_fee));
            $this->assertSame($round($fees[$rule->id]->per_km_fee), Money::toStorage((string) $rule->per_km_fee));
        }
        foreach (DB::table('offers')->get() as $offer) {
            $expected = $offer->discount_type === 'fixed' ? $round($offers[$offer->id]->discount_value) : Money::toStorage((string) $offers[$offer->id]->discount_value);
            $this->assertSame($expected, Money::toStorage((string) $offer->discount_value), 'Percent offers are unchanged.');
        }
        foreach (DB::table('plus_cycle_discounts')->get() as $d) {
            $before = $cycleDiscounts[$d->id];
            $this->assertSame((string) $before->snapshot_discount_value, (string) $d->snapshot_discount_value, 'Snapshots are history.');
            if ((int) $before->op_currency_id === $usd->id) {
                $this->assertSame($syp->id, (int) $d->op_currency_id);
            }
        }

        // Rates re-based: one default (SYP = 1), USD = 1/r, others / r.
        $this->assertSame(1, Currency::where('is_default', true)->count());
        $this->assertTrue($syp->fresh()->is_default);
        $this->assertSame(0, BigDecimal::of('1')->compareTo((string) $syp->fresh()->exchange_rate));
        $this->assertSame(0, BigDecimal::one()->dividedBy($rate, 12, RoundingMode::HALF_UP)->compareTo((string) $usd->fresh()->exchange_rate));
        foreach ($others as $c) {
            $this->assertSame(0, BigDecimal::of((string) $c->exchange_rate)->dividedBy($rate, 12, RoundingMode::HALF_UP)->compareTo((string) $c->fresh()->exchange_rate));
        }

        // History untouched.
        $this->assertEquals($history, $this->snapshot(self::HISTORY));

        // Idempotent: re-running does not convert twice.
        $after = DB::table('branch_products')->pluck('price', 'id');
        $this->service()->execute($run->fresh());
        $this->assertEquals($after, DB::table('branch_products')->pluck('price', 'id'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'currency.redenomination_completed']);
    }

    public function test_checkout_after_a_switch_prices_in_the_new_default(): void
    {
        config(['waffrli.currency.redenomination_enabled' => true]);
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $this->service()->execute($this->service()->request($this->admin(), $syp->id, 'switch-checkout'));

        $user = $this->demoCustomer(1); // Damascus
        $token = $this->loginCustomer($user);
        $branch = Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-fresh-market'))->whereHas('city', fn ($q) => $q->where('name->en', 'Damascus'))->firstOrFail();
        $bp = BranchProduct::where('branch_id', $branch->id)->where('is_available', true)
            ->whereDoesntHave('product.optionGroups', fn ($q) => $q->where('min_select', '>', 0))->with('product')->firstOrFail();
        $add = fn () => $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $branch->id, 'product_id' => $bp->product_id, 'quantity' => 3])->assertOk()->json('data.cart');

        $cart = $add();
        $order = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertOk()->json('data.order');
        $this->assertSame('SYP', $order['currency']['code'], 'The new default is the order currency.');
        $this->assertSame(0, BigDecimal::of((string) $bp->price)->multipliedBy(3)->compareTo($order['amounts']['subtotal']), 'Converted catalog price, rate 1.');

        // Paying in the former default converts with the re-based rate.
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $cart = $add();
        $inUsd = $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout", ['currency_id' => $usd->id])->assertOk()->json('data.order');
        $unit = BigDecimal::of((string) $bp->price)->multipliedBy((string) $usd->exchange_rate)->toScale((int) $usd->decimals, RoundingMode::HALF_UP);
        $this->assertSame(0, $unit->multipliedBy(3)->compareTo($inUsd['amounts']['subtotal']));
    }

    public function test_a_failure_part_way_changes_nothing(): void
    {
        config(['waffrli.currency.redenomination_enabled' => true]);
        // A currency so "large" that a fixed offer would round to zero (rate 0.00001, 0 decimals).
        $big = Currency::create(['code' => 'XBG', 'name' => ['en' => 'Big'], 'symbol' => 'B', 'decimals' => 0, 'exchange_rate' => '0.00001', 'is_active' => true]);
        $this->assertTrue(DB::table('offers')->where('discount_type', 'fixed')->exists());
        $catalog = $this->snapshot(['branch_products', 'branch_product_options', 'offers', 'delivery_fee_rules', 'currencies', 'plus_cycle_discounts']);

        $run = $this->service()->request($this->admin(), $big->id, 'switch-zero');
        try {
            $this->service()->execute($run);
            $this->fail('Rounding a positive fixed discount to zero must abort.');
        } catch (BusinessRuleException) {
        }

        $this->assertSame(CurrencyRedenomination::STATUS_FAILED, $run->fresh()->status);
        $this->assertStringContainsString('discount_value', $run->fresh()->error);
        $this->assertEquals($catalog, $this->snapshot(['branch_products', 'branch_product_options', 'offers', 'delivery_fee_rules', 'currencies', 'plus_cycle_discounts']), 'Atomic: branch prices converted before the failure were rolled back.');

        // A failed run frees the in-flight slot.
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $this->assertSame(CurrencyRedenomination::STATUS_PENDING, $this->service()->request($this->admin(), $syp->id, 'switch-after-failure')->status);
    }

    public function test_stale_requests_are_refused_and_pending_runs_resume_from_the_command(): void
    {
        config(['waffrli.currency.redenomination_enabled' => true]);
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $run = $this->service()->request($this->admin(), $syp->id, 'switch-stale');
        $syp->forceFill(['exchange_rate' => '14000'])->save(); // rate changed after the request
        try {
            $this->service()->execute($run);
            $this->fail('A rate change after the request makes it stale.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }
        $this->assertSame(CurrencyRedenomination::STATUS_FAILED, $run->fresh()->status);

        $run = $this->service()->request($this->admin(), $syp->id, 'switch-resume');
        $this->artisan('waffrli:currency-switch:resume', ['run' => $run->id])->assertSuccessful();
        $this->assertSame(CurrencyRedenomination::STATUS_COMPLETED, $run->fresh()->status);
        $this->artisan('waffrli:currency-switch:resume', ['run' => $run->id])->expectsOutputToContain('completed; nothing to do')->assertSuccessful();
    }

    public function test_filament_switch_action_is_gated_and_requires_typing_the_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $page = ListCurrencyRedenominations::class;
        $action = TestAction::make('switchDefault');

        $this->actingAs($this->admin(), 'admin_web');
        Livewire::test($page)->assertActionHidden($action); // flag off

        config(['waffrli.currency.redenomination_enabled' => true]);
        $manager = Admin::factory()->create(['is_active' => true]);
        $manager->givePermissionTo('currencies.manage');
        $this->actingAs($manager, 'admin_web');
        Livewire::test($page)->assertActionHidden($action); // no currencies.change_default

        $this->actingAs($this->admin(), 'admin_web');
        Livewire::test($page)->callAction($action, ['to_currency_id' => $syp->id, 'confirm_code' => 'EUR'])
            ->assertHasFormErrors(['confirm_code']);
        $this->assertSame(0, CurrencyRedenomination::count());

        Livewire::test($page)->callAction($action, ['to_currency_id' => $syp->id, 'confirm_code' => 'syp'])->assertHasNoFormErrors();
        $this->assertSame(CurrencyRedenomination::STATUS_COMPLETED, CurrencyRedenomination::sole()->status, 'Queued job ran (sync queue in tests).');
        $this->assertTrue($syp->fresh()->is_default);
    }
}
