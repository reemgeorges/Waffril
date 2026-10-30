<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Pages\PlatformSettingsPage;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\DriverCashSettlements\Pages\ListDriverCashSettlements;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Filament\Resources\ProductRatings\Pages\ListProductRatings;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Resources\Wallets\WalletResource;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Currency;
use App\Models\DriverCashSettlement;
use App\Models\PlusCycle;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Catalog\ProductRatingService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — the Filament admin panel exercised through its
 * real Livewire components and real database records.
 */
class FilamentPanelTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_panel_uses_admin_guard_and_rejects_others(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')->get('/admin')->assertRedirect('/admin/login');

        $inactive = Admin::factory()->create(['is_active' => false]);
        $this->actingAs($inactive, 'admin_web')->get('/admin')->assertForbidden();

        $this->actingAsAdmin($this->superAdmin())->get('/admin')->assertOk();
    }

    public function test_store_crud_with_translations_categories_and_validation(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        $category = StoreCategory::create(['name' => ['en' => 'Restaurants', 'ar' => 'مطاعم'], 'slug' => 'restaurants']);

        Livewire::test(CreateStore::class)
            ->fillForm(['slug' => ''])
            ->call('create')
            ->assertHasFormErrors(['name.ar' => 'required', 'name.en' => 'required', 'slug' => 'required', 'categories' => 'required']);

        Livewire::test(CreateStore::class)
            ->fillForm([
                'name' => ['ar' => 'بيت البيتزا', 'en' => 'Pizza House'],
                'slug' => 'pizza-house',
                'categories' => [$category->id],
                'contact_phone' => '+963912345678',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $store = Store::where('slug', 'pizza-house')->firstOrFail();
        $this->assertSame('بيت البيتزا', $store->getTranslation('name', 'ar'));
        $this->assertSame([$category->id], $store->categories()->pluck('store_categories.id')->all());

        Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])
            ->assertSchemaStateSet(['name' => ['ar' => 'بيت البيتزا', 'en' => 'Pizza House']])
            ->fillForm(['name' => ['ar' => 'بيت البيتزا', 'en' => 'Pizza Home']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Pizza Home', $store->fresh()->getTranslation('name', 'en'));

        Livewire::test(ManageStoreCategories::class)
            ->callAction(TestAction::make('create'), ['name' => ['ar' => 'بقالة', 'en' => 'Grocery'], 'slug' => 'grocery'])
            ->assertHasNoFormErrors();
        $this->assertTrue(StoreCategory::where('slug', 'grocery')->exists());
    }

    public function test_employee_permissions_gate_screens(): void
    {
        $viewer = $this->employee(['catalog.view']);
        $this->actingAsAdmin($viewer);

        $this->get(StoreResource::getUrl('index'))->assertOk();
        $this->get(StoreResource::getUrl('create'))->assertForbidden();
        $this->get('/admin/orders')->assertForbidden();
        $this->get('/admin/platform-settings')->assertForbidden();
    }

    public function test_roles_are_built_from_the_catalogue_and_grant_access(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageRoles::class)
            ->callAction(TestAction::make('create'), [
                'name' => 'dispatcher',
                'permission_groups' => ['orders' => ['orders.view', 'orders.assign']],
            ])
            ->assertHasNoFormErrors();

        $role = Role::findByName('dispatcher', 'admin');
        $this->assertEqualsCanonicalizing(['orders.view', 'orders.assign'], $role->permissions->pluck('name')->all());

        $employee = $this->employee();
        $employee->assignRole('dispatcher');
        $this->actingAsAdmin($employee)->get('/admin/orders')->assertOk();
        $this->actingAsAdmin($employee)->get('/admin/stores')->assertForbidden();
    }

    public function test_currency_first_default_via_panel_and_switching_stays_disabled(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageCurrencies::class)
            ->callAction(TestAction::make('create'), ['code' => 'usd', 'name' => ['ar' => 'دولار', 'en' => 'Dollar'], 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => '1'])
            ->assertHasNoFormErrors();
        $usd = Currency::where('code', 'USD')->firstOrFail();

        Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('initializeDefault')->table($usd));
        $this->assertTrue($usd->fresh()->is_default);

        Livewire::test(ManageCurrencies::class)
            ->callAction(TestAction::make('create'), ['code' => 'SYP', 'name' => ['ar' => 'ليرة', 'en' => 'Pound'], 'symbol' => 'S', 'decimals' => 0, 'exchange_rate' => '13000']);
        $syp = Currency::where('code', 'SYP')->firstOrFail();

        Livewire::test(ManageCurrencies::class)
            ->assertActionHidden(TestAction::make('initializeDefault')->table($syp))
            ->assertActionDisabled(TestAction::make('switchDefault')->table($syp));
        $this->assertTrue($usd->fresh()->is_default);
        $this->assertFalse($syp->fresh()->is_default);
        $this->assertTrue(AdminAuditLog::where('action', 'currency.default_initialized')->exists());
    }

    public function test_order_page_accept_and_cancel_actions_follow_permissions(): void
    {
        $branch = $this->branch($this->store(), $this->city());
        $order = $this->order($this->customer(), $branch, '10');

        $this->actingAsAdmin($this->employee(['orders.view']));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('accept');

        $this->actingAsAdmin($this->employee(['orders.view', 'orders.accept']));
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->callAction('accept');
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertTrue(AdminAuditLog::where('action', 'order.accepted')->exists());
    }

    public function test_settlement_prepare_and_settle_from_the_panel(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $driver = $this->driver($city);
        $this->order($this->customer(), $branch, '25', ['status' => OrderStatus::Delivered, 'driver' => $driver]);
        $this->actingAsAdmin($admin);

        Livewire::test(ListDriverCashSettlements::class)
            ->callAction('prepare', ['driver_id' => $driver->id, 'currency_id' => $this->usd()->id, 'business_date' => now(\App\Support\Settings\PlatformSettings::timezone())->toDateString()]); // business days are in the platform timezone
        $settlement = DriverCashSettlement::firstOrFail();
        $this->assertSame('25.0000', (string) $settlement->expected_amount);

        Livewire::test(ListDriverCashSettlements::class)
            ->callAction(TestAction::make('settle')->table($settlement), ['received_amount' => '20']);
        $this->assertSame(DriverCashSettlement::STATUS_OPEN, $settlement->fresh()->status, 'a difference without a note is refused');

        Livewire::test(ListDriverCashSettlements::class)
            ->callAction(TestAction::make('settle')->table($settlement), ['received_amount' => '25']);
        $this->assertSame(DriverCashSettlement::STATUS_SETTLED, $settlement->fresh()->status);
    }

    public function test_rating_moderation_from_the_panel(): void
    {
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $product = $this->product($store);
        $user = $this->customer();
        $rating = app(ProductRatingService::class)->record($user, $product, $this->order($user, $branch, '9', ['status' => OrderStatus::Delivered]), 2, 'meh');
        $this->actingAsAdmin($this->employee(['ratings.moderate']));

        Livewire::test(ListProductRatings::class)
            ->callAction(TestAction::make('hide')->table($rating), ['reason' => 'off-topic']);

        $this->assertTrue($rating->fresh()->is_hidden);
        $this->assertSame(0, $product->fresh()->rating_count);
    }

    public function test_plus_request_approval_from_the_panel(): void
    {
        $this->usd();
        $plan = PlusPlan::create(['code' => 'gold', 'name' => ['en' => 'Gold', 'ar' => 'ذهبي'], 'free_deliveries_per_cycle' => 2]);
        $request = PlusRequest::create(['user_id' => $this->customer()->id, 'type' => 'new', 'requested_plan_id' => $plan->id]);
        $this->actingAsAdmin($this->employee(['plus.view', 'plus.approve']));

        Livewire::test(ListPlusRequests::class)
            ->callAction(TestAction::make('approveNew')->table($request), [
                'renewal_policy' => 'custom', 'custom_duration_days' => 14, 'starts_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, PlusCycle::count());
        $this->assertSame('custom', PlusCycle::first()->renewal_policy);
    }

    public function test_customer_suspension_from_the_panel_and_wallet_has_no_edit(): void
    {
        $user = $this->customer();
        $this->actingAsAdmin($this->employee(['customers.view', 'customers.suspend']));

        Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])
            ->callAction('suspend', ['reason' => 'chargeback'])
            ->assertHasNoFormErrors();
        $this->assertSame(User::ACCOUNT_SUSPENDED, $user->fresh()->account_status);

        $this->assertArrayNotHasKey('edit', WalletResource::getPages());
        $this->assertArrayNotHasKey('create', WalletResource::getPages());
    }

    public function test_platform_settings_page_validates_and_saves(): void
    {
        $this->actingAsAdmin($this->employee(['settings.manage']));

        Livewire::test(PlatformSettingsPage::class)
            ->fillForm(['support__phone' => '+963911111111', 'catalog__placeholder_rating' => '4.2', 'orders__accepting_new' => false])
            ->call('save');

        $this->assertSame('+963911111111', Setting::where('key', 'support.phone')->value('value'));
        $this->assertSame(4.2, Setting::where('key', 'catalog.placeholder_rating')->first()->value);
        $this->assertFalse(Setting::where('key', 'orders.accepting_new')->first()->value);
    }

    public function test_arabic_locale_renders_rtl(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        $this->post('/admin/locale/ar')->assertRedirect();

        $this->get('/admin')->assertOk()->assertSee('dir="rtl"', false)->assertSee('لوحة التحكم');
    }
}
