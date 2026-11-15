<?php

namespace Tests\Feature\Currency;

use App\Filament\Resources\PlusPlans\Pages\EditPlusPlan;
use App\Filament\Resources\PlusPlans\RelationManagers\StoreDiscountsRelationManager;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\BranchPricesRelationManager;
use App\Filament\Support\Fields;
use App\Models\BranchProduct;
use App\Models\Currency;
use App\Models\PlusPlan;
use App\Models\PlusStoreDiscount;
use App\Services\Currency\OperationalMoney;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * SYP-only operation (2026-11-15): base-stored catalog amounts are shown and
 * typed in the operational currency (SYP) without rewriting storage, without
 * drift and only with an explicitly approved rate.
 */
class OperationalMoneyTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected Currency $syp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usd();
        $this->syp = Currency::create(['code' => 'SYP', 'name' => ['en' => 'Syrian Pound', 'ar' => 'ليرة سورية'], 'symbol' => 'SYP', 'decimals' => 0, 'exchange_rate' => '13000'])->fresh();
        config([
            'waffrli.currency.customer_currencies' => ['SYP'],
            'waffrli.currency.operational_default' => 'SYP',
            'waffrli.currency.approved_operational_rate' => null,
        ]);
        OperationalMoney::flush();
    }

    /** Approval is given in NEW SYP per USD (the stored 13000 old SYP = 130 new). */
    protected function approve(?string $rate = '130'): void
    {
        config(['waffrli.currency.approved_operational_rate' => $rate]);
        OperationalMoney::flush();
    }

    /** A stored DECIMAL(18,4) as its canonical string (SQLite returns floats). */
    protected function stored($model, string $field): string
    {
        return Money::toStorage((string) $model->getRawOriginal($field));
    }

    public function test_conversion_uses_the_stored_rate_and_the_inverse_is_exact(): void
    {
        $this->assertSame('USD', OperationalMoney::base()->code);
        $this->assertSame('SYP', OperationalMoney::currency()->code);
        // fromBase() yields STORED units (old SYP); the display divides by 100 once.
        $this->assertSame('32500', (string) OperationalMoney::fromBase('2.50'));
        $this->assertSame('14999', (string) OperationalMoney::fromBase('1.1538'));
        $this->assertSame('325.00', Denomination::presentString(OperationalMoney::fromBase('2.50'), $this->syp));

        // toBase() takes the TYPED new-SYP amount.
        $this->assertSame(['exact' => '2.5000', 'lower' => null, 'upper' => null], OperationalMoney::toBase('325'));
        $this->assertSame('1.1539', OperationalMoney::toBase('150.01')['exact']);
        $this->assertSame('0.0000', OperationalMoney::toBase('0')['exact']);
        // A stored-unit number is never taken as new SYP: 32,500 new = 3,250,000 old.
        $this->assertSame('250.0000', OperationalMoney::toBase('32500')['exact']);

        // 0.0001 USD is 1.3 old SYP (0.013 new): 150 new SYP has no exact 4-decimal USD amount.
        $none = OperationalMoney::toBase('150');
        $this->assertNull($none['exact']);
        $this->assertSame('149.99', Denomination::presentString($none['lower'], $this->syp));
        $this->assertSame('150.01', Denomination::presentString($none['upper'], $this->syp));

        // New SYP has 2 decimals (qirsh); a third is never storable; negatives neither.
        $this->assertNotNull(OperationalMoney::toBase('100.50')['exact']);
        $this->assertNull(OperationalMoney::toBase('100.555')['exact']);
        $this->assertNull(OperationalMoney::toBase('-1')['exact']);

        // Round trip: every exact answer converts back to the typed new-SYP amount.
        foreach (range(0, 60000, 7) as $qirsh) {
            $typed = Money::of($qirsh)->dividedBy(100, 2);
            $exact = OperationalMoney::toBase($typed)['exact'];
            if ($exact !== null) {
                $this->assertTrue(Denomination::present(OperationalMoney::fromBase($exact), $this->syp)->isEqualTo($typed), "round trip of {$typed}");
            }
        }
        // Every stored amount is reachable: base → new SYP → base never drifts.
        foreach (range(0, 3000, 3) as $steps) {
            $base = Money::toStorage(Money::of($steps)->multipliedBy('0.0001'));
            $shown = OperationalMoney::fromBase($base);
            $back = OperationalMoney::toBase(Denomination::present($shown, $this->syp))['exact'];
            $this->assertNotNull($back);
            $this->assertTrue(OperationalMoney::fromBase($back)->isEqualTo($shown));
        }
    }

    public function test_only_an_exactly_approved_rate_enables_operational_input(): void
    {
        $this->assertFalse(OperationalMoney::rateApproved(), 'no approval configured: the demo rate is not approved');
        $this->assertFalse(OperationalMoney::inputEnabled());

        $this->approve('130.000000');
        $this->assertTrue(OperationalMoney::inputEnabled());

        // The OLD-denomination number is not the approved new-SYP rate (no double conversion).
        $this->approve('13000');
        $this->assertFalse(OperationalMoney::inputEnabled());

        $this->approve('129.99');
        $this->assertFalse(OperationalMoney::inputEnabled());

        $this->approve('not-a-number');
        $this->assertFalse(OperationalMoney::inputEnabled());

        // A changed stored rate revokes the approval.
        $this->approve('130');
        $this->syp->update(['exchange_rate' => '14000']);
        OperationalMoney::flush();
        $this->assertFalse(OperationalMoney::inputEnabled());
    }

    public function test_missing_or_invalid_rate_never_produces_an_amount(): void
    {
        $this->approve();
        $this->syp->update(['exchange_rate' => '0']);
        OperationalMoney::flush();

        $this->assertNull(OperationalMoney::rate());
        $this->assertNull(OperationalMoney::fromBase('2.50'));
        $this->assertNull(OperationalMoney::toBase('32500')['exact']);
        $this->assertFalse(OperationalMoney::inputEnabled());
        $this->assertSame(__('admin.money.no_operational_rate'), Fields::catalogMoney('2.50'));

        // SYP not offered: no fallback to USD.
        config(['waffrli.currency.customer_currencies' => []]);
        OperationalMoney::flush();
        $this->assertNull(OperationalMoney::currency());
        $this->assertSame(__('admin.money.no_operational_rate'), Fields::catalogMoney('2.50'));
    }

    public function test_money_is_localized_and_records_keep_their_own_currency(): void
    {
        app()->setLocale('ar');
        // 2.50 USD = 32,500 old SYP = 325 new SYP (never "32,500" as new pounds).
        $this->assertSame("\u{2066}325 ل.س\u{2069}", Fields::catalogMoney('2.50'));
        $this->assertSame("\u{2066}150 ل.س\u{2069}", Fields::formatMoney('15000.0000', $this->syp));
        $this->assertSame("\u{2066}1,500,000.50 ل.س\u{2069}", Fields::formatMoney('150000050', $this->syp));
        $this->assertSame("\u{2066}3.34 USD\u{2069}", Fields::formatMoney('3.3400', $this->usd()));

        app()->setLocale('en');
        $this->assertSame("\u{2066}150 SYP\u{2069}", Fields::formatMoney('15000.0000', $this->syp));
        $this->assertSame("\u{2066}0.05 SYP\u{2069}", Fields::formatMoney('5', $this->syp), '5 old pounds = 5 qirsh');
        // The original old-pound record stays visible for audit.
        $this->assertSame(__('admin.money.stored_denomination', ['amount' => '15,000', 'code' => 'SYP', 'denomination' => __('admin.money.denomination_old')]),
            Fields::storedDenominationNote('15000.0000', $this->syp));
        $this->assertNull(Fields::storedDenominationNote('3.34', $this->usd()));
        // A historical USD amount is never relabelled or converted.
        $this->assertSame("\u{2066}3.34 USD\u{2069}", Fields::formatMoney('3.3400', $this->usd()));
        $this->assertNotNull(Fields::historicalCurrencyNote($this->usd()));
        $this->assertNull(Fields::historicalCurrencyNote($this->syp));

        // Unapproved rate: shown, but flagged in the audit tooltip.
        $this->assertStringContainsString(__('admin.money.rate_unconfirmed'), Fields::storedTooltip('2.50'));
        $this->approve();
        $this->assertStringContainsString(__('admin.money.rate_approved'), Fields::storedTooltip('2.50'));
    }

    public function test_admin_types_syp_prices_stored_exactly_without_drift(): void
    {
        $this->approve();
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $product = $this->product($store);
        $this->actingAsAdmin($this->superAdmin());
        $manager = fn () => Livewire::test(BranchPricesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);

        // Not storable exactly at 4 decimals: rejected, nothing saved.
        // New SYP typed. Not storable exactly at 4 decimals: rejected, nothing saved.
        $manager()->callAction(TestAction::make('create')->table(), ['branch_id' => $branch->id, 'price' => '150', 'is_available' => true])
            ->assertHasFormErrors(['price']);
        $manager()->callAction(TestAction::make('create')->table(), ['branch_id' => $branch->id, 'price' => '150.015', 'is_available' => true])
            ->assertHasFormErrors(['price']);
        $this->assertSame(0, BranchProduct::query()->count());

        // Storable: saved as the exact base amount; customers are charged 150.01 new SYP.
        $manager()->callAction(TestAction::make('create')->table(), ['branch_id' => $branch->id, 'price' => '150.01', 'is_available' => true])
            ->assertHasNoFormErrors();
        $price = BranchProduct::query()->sole();
        $this->assertSame('1.1539', $this->stored($price, 'price'));
        $this->assertSame('150.01', Denomination::presentString(OperationalMoney::fromBase($price->price), $this->syp));

        // A legacy base amount: the form shows new SYP; saving unchanged keeps it byte for byte.
        $price->forceFill(['price' => '1.1538'])->save();
        $manager()->mountAction(TestAction::make('edit')->table($price))->assertSchemaStateSet(['price' => '149.99']);
        $manager()->callAction(TestAction::make('edit')->table($price), ['price' => '149.99'])->assertHasNoFormErrors();
        $this->assertSame('1.1538', $this->stored($price->fresh(), 'price'));

        // A changed amount is stored exactly (260 new = 26,000 old = 2 USD).
        $manager()->callAction(TestAction::make('edit')->table($price), ['price' => '260'])->assertHasNoFormErrors();
        $this->assertSame('2.0000', $this->stored($price->fresh(), 'price'));
    }

    public function test_without_an_approved_rate_admins_keep_typing_base_amounts(): void
    {
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $product = $this->product($store);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(BranchPricesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callAction(TestAction::make('create')->table(), ['branch_id' => $branch->id, 'price' => '2.50', 'is_available' => true])
            ->assertHasNoFormErrors();
        $this->assertSame('2.5000', $this->stored(BranchProduct::query()->sole(), 'price'));
        $this->assertStringContainsString(__('admin.money.rate_unconfirmed'), Fields::operationalPreview('2.50'));
    }

    public function test_fixed_discounts_are_typed_in_syp_and_percentages_are_untouched(): void
    {
        $this->approve();
        $plan = PlusPlan::create(['name' => ['en' => 'Plus', 'ar' => 'بلس'], 'code' => 'plus-test', 'default_duration_days' => 30, 'is_active' => true]);
        $fixedStore = $this->store();
        $percentStore = $this->store();
        $this->actingAsAdmin($this->superAdmin());
        $manager = fn () => Livewire::test(StoreDiscountsRelationManager::class, ['ownerRecord' => $plan, 'pageClass' => EditPlusPlan::class]);

        $manager()->callAction(TestAction::make('create')->table(), [
            'store_id' => $fixedStore->id, 'discount_type' => 'fixed', 'discount_value' => '26', 'max_discount_amount' => null, 'min_order_amount' => '650', 'is_active' => true,
        ])->assertHasNoFormErrors();
        $manager()->callAction(TestAction::make('create')->table(), [
            'store_id' => $percentStore->id, 'discount_type' => 'percent', 'discount_value' => '12.5', 'is_active' => true,
        ])->assertHasNoFormErrors();

        $fixed = PlusStoreDiscount::query()->where('store_id', $fixedStore->id)->sole();
        $this->assertSame('0.2000', $this->stored($fixed, 'discount_value'));
        $this->assertSame('5.0000', $this->stored($fixed, 'min_order_amount'));
        $percent = PlusStoreDiscount::query()->where('store_id', $percentStore->id)->sole();
        $this->assertSame('12.5000', $this->stored($percent, 'discount_value'));
    }
}
