<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\Stores\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\PlusStatus;
use App\Filament\Widgets\OperationsOverview;
use App\Models\AdminAuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Catalog\CategoryDeletionService;
use App\Services\Platform\PlatformSettingsService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WaffrliFixtures;

/**
 * UX overhaul (2026-11-12): store-centric catalog, safe category deletion,
 * customer PLUS visibility and PLUS request search/filters — with the
 * existing permissions.
 */
class AdminWorkflowOverhaulTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_store_page_manages_its_branches_and_products_in_context(): void
    {
        $store = $this->store();
        $city = $this->city();
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(BranchesRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->callAction(TestAction::make('create')->table(), [
                'city_id' => $city->id,
                'name' => ['ar' => 'فرع المزة', 'en' => 'Mazzeh'],
                'address' => ['ar' => 'المزة', 'en' => 'Mazzeh street'],
                'lat' => 33.5, 'lng' => 36.27, 'is_active' => true, 'is_accepting_orders' => true,
            ])->assertHasNoFormErrors();
        $branch = Branch::query()->where('store_id', $store->id)->sole();
        $this->assertSame('Mazzeh', $branch->getTranslation('name', 'en'));

        $category = Category::create(['name' => ['ar' => 'مشاوي', 'en' => 'Grills'], 'slug' => 'grills']);
        Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->callAction(TestAction::make('create')->table(), [
                'name' => ['ar' => 'شاورما', 'en' => 'Shawarma'], 'categories' => [$category->id], 'is_active' => true,
            ])->assertHasNoFormErrors();
        $product = Product::query()->where('store_id', $store->id)->sole();
        $this->assertSame([$category->id], $product->categories()->pluck('categories.id')->all());

        // The branch page reads as part of its store.
        $breadcrumbs = Livewire::test(EditBranch::class, ['record' => $branch->getRouteKey()])->instance()->getBreadcrumbs();
        $this->assertContains($store->name, $breadcrumbs);
    }

    public function test_store_scoped_creation_follows_the_catalog_permission(): void
    {
        $store = $this->store();
        $this->actingAsAdmin($this->employee(['catalog.view']));

        Livewire::test(BranchesRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->assertActionHidden(TestAction::make('create')->table());
        Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->assertActionHidden(TestAction::make('create')->table());
    }

    public function test_deleting_a_store_category_moves_its_stores_and_never_deletes_them(): void
    {
        $old = StoreCategory::create(['name' => ['ar' => 'قديم', 'en' => 'Old'], 'slug' => 'old']);
        $fallback = StoreCategory::create(['name' => ['ar' => 'عام', 'en' => 'General'], 'slug' => 'general']);
        $stores = collect([$this->store(), $this->store()])->each(fn (Store $s) => $s->categories()->attach($old));
        $admin = $this->superAdmin();
        app(PlatformSettingsService::class)->save(['catalog.fallback_store_category_id' => $fallback->id], $admin);
        $this->actingAsAdmin($admin);

        // The dialog suggests the configured fallback and requires confirmation.
        Livewire::test(ManageStoreCategories::class)
            ->mountAction(TestAction::make('deleteSafely')->table($old))
            ->assertSchemaStateSet(['replacement_id' => $fallback->id])
            ->callMountedAction(['replacement_id' => $fallback->id, 'confirm' => false])
            ->assertHasFormErrors(['confirm']);
        $this->assertFalse($old->fresh()->trashed());

        Livewire::test(ManageStoreCategories::class)
            ->callAction(TestAction::make('deleteSafely')->table($old), ['replacement_id' => $fallback->id, 'confirm' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue($old->fresh()->trashed());
        foreach ($stores as $store) {
            $this->assertNotNull(Store::find($store->id), 'stores are never deleted');
            $this->assertSame([$fallback->id], $store->categories()->pluck('store_categories.id')->all());
        }
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'catalog.store_category_deleted')->count());
    }

    public function test_a_category_in_use_cannot_be_deleted_without_a_replacement(): void
    {
        $category = Category::create(['name' => ['ar' => 'أ', 'en' => 'A'], 'slug' => 'a']);
        $product = $this->product($this->store());
        $product->categories()->attach($category);

        $this->expectException(BusinessRuleException::class);
        app(CategoryDeletionService::class)->deleteProductCategory($category, null, $this->superAdmin());
    }

    public function test_deleting_a_product_category_moves_its_products(): void
    {
        $old = Category::create(['name' => ['ar' => 'قديم', 'en' => 'Old'], 'slug' => 'old-p']);
        $new = Category::create(['name' => ['ar' => 'جديد', 'en' => 'New'], 'slug' => 'new-p']);
        $product = $this->product($this->store());
        $product->categories()->attach([$old->id, $new->id]);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageCategories::class)
            ->callAction(TestAction::make('deleteSafely')->table($old), ['replacement_id' => $new->id, 'confirm' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue($old->fresh()->trashed());
        $this->assertSame([$new->id], $product->categories()->pluck('categories.id')->all(), 'no duplicate link');
    }

    public function test_employees_without_catalog_manage_cannot_delete_categories(): void
    {
        $category = StoreCategory::create(['name' => ['ar' => 'أ', 'en' => 'A'], 'slug' => 'a-s']);
        $this->actingAsAdmin($this->employee(['catalog.view']));

        Livewire::test(ManageStoreCategories::class)->assertActionHidden(TestAction::make('deleteSafely')->table($category));
        $this->expectException(BusinessRuleException::class);
        app(CategoryDeletionService::class)->deleteStoreCategory($category, null, $this->employee(['catalog.view']));
    }

    /** @return array{0: User, 1: User, 2: User, 3: PlusPlan} member, waiting, none, plan */
    protected function plusCustomers(): array
    {
        $this->usd();
        $plan = PlusPlan::create(['code' => 'gold', 'name' => ['en' => 'Gold', 'ar' => 'ذهبي'], 'free_deliveries_per_cycle' => 4]);
        $member = $this->customer();
        $approver = $this->superAdmin();
        $membership = PlusMembership::create(['user_id' => $member->id, 'plus_plan_id' => $plan->id, 'renewal_policy' => 'monthly', 'status' => PlusMembership::STATUS_ACTIVE, 'policy_set_by_admin_id' => $approver->id]);
        PlusCycle::create([
            'confirmed_by_admin_id' => $approver->id, 'confirmed_at' => now(), 'snapshot_currency_id' => $this->usd()->id,
            'plus_membership_id' => $membership->id, 'user_id' => $member->id, 'plus_plan_id' => $plan->id, 'cycle_number' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'status' => PlusCycle::STATUS_ACTIVE,
            'plan_name' => ['en' => 'Gold', 'ar' => 'ذهبي'], 'renewal_policy' => 'monthly', 'free_deliveries_total' => 4, 'free_deliveries_used' => 1,
        ]);
        $waiting = $this->customer();
        PlusRequest::create(['user_id' => $waiting->id, 'type' => 'new', 'requested_plan_id' => $plan->id]);

        return [$member, $waiting, $this->customer(), $plan];
    }

    public function test_customers_show_real_plus_status_and_can_be_filtered_by_it(): void
    {
        [$member, $waiting, $none] = $this->plusCustomers();
        $this->actingAsAdmin($this->superAdmin());

        $this->assertSame(PlusStatus::ACTIVE, PlusStatus::for($member->fresh())['state']);
        $this->assertSame(PlusStatus::PENDING, PlusStatus::for($waiting->fresh())['state'], 'a request is not a membership');
        $this->assertSame(PlusStatus::NONE, PlusStatus::for($none->fresh())['state']);

        Livewire::test(ListCustomers::class)
            ->filterTable('plus', PlusStatus::ACTIVE)->assertCanSeeTableRecords([$member])->assertCanNotSeeTableRecords([$waiting, $none])
            ->filterTable('plus', PlusStatus::PENDING)->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$member, $none]);

        Livewire::test(ViewCustomer::class, ['record' => $member->getRouteKey()])
            ->assertSee(__('admin.plus_status.active'))->assertSee(PlusStatus::for($member->fresh())['plan'])->assertSee('3 / 4');
        $this->get(CustomerResource::getUrl('view', ['record' => $member]))->assertOk();
    }

    public function test_plus_requests_can_be_searched_and_filtered(): void
    {
        [, $waiting, , $plan] = $this->plusCustomers();
        $silver = PlusPlan::create(['code' => 'silver', 'name' => ['en' => 'Silver', 'ar' => 'فضي'], 'free_deliveries_per_cycle' => 2]);
        $other = $this->customer();
        $renew = PlusRequest::create(['user_id' => $other->id, 'type' => 'renew', 'requested_plan_id' => $silver->id]);
        $rejected = PlusRequest::create(['user_id' => $other->id, 'type' => 'new', 'requested_plan_id' => $plan->id, 'status' => 'rejected']);
        $rejected->forceFill(['created_at' => now()->subDays(10)])->save();
        $new = PlusRequest::query()->where('user_id', $waiting->id)->sole();
        $this->actingAsAdmin($this->employee(['plus.view']));

        $page = Livewire::test(ListPlusRequests::class);
        $page->assertCanSeeTableRecords([$new, $renew])->assertCanNotSeeTableRecords([$rejected]); // pending tab
        $page->searchTable($waiting->phone)->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$renew]);
        $page->searchTable(null)->filterTable('type', ['renew'])->assertCanSeeTableRecords([$renew])->assertCanNotSeeTableRecords([$new]);
        $page->removeTableFilters()->filterTable('requested_plan_id', $silver->id)->assertCanSeeTableRecords([$renew])->assertCanNotSeeTableRecords([$new]);

        Livewire::test(ListPlusRequests::class)->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$new, $renew]);
        Livewire::test(ListPlusRequests::class)->set('activeTab', 'all')
            ->filterTable('requested_between', ['from' => now()->subDays(11)->toDateString(), 'until' => now()->subDays(9)->toDateString()])
            ->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$new, $renew]);

        // Review actions stay permission-bound: plus.view alone cannot approve.
        Livewire::test(ListPlusRequests::class)->assertActionHidden(TestAction::make('approveNew')->table($new))
            ->assertActionVisible(TestAction::make('details')->table($new));
    }

    /** @return list<string> every label in the sidebar the current admin sees (groups expanded). */
    protected function sidebarLabels(): array
    {
        $labels = [];
        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $labels[] = $item->getLabel();
                foreach ($item->getChildItems() as $child) {
                    $labels[] = $child->getLabel();
                }
            }
        }

        return $labels;
    }

    /** One fresh application per language (Filament builds the menu once per request). */
    public static function locales(): array
    {
        return ['Arabic' => ['ar'], 'English' => ['en']];
    }

    #[DataProvider('locales')]
    public function test_redundant_catalog_entries_are_not_in_the_sidebar_but_stay_reachable_in_context(string $locale): void
    {
        $hidden = [
            ProductResource::class,
            ProductRatingResource::class,
            BranchResource::class,
            CategoryResource::class,
            StoreCategoryResource::class,
        ];
        foreach ([$locale] as $locale) {
            app()->setLocale($locale);
            $this->actingAsAdmin($this->superAdmin());
            $labels = $this->sidebarLabels();
            $this->assertContains(StoreResource::getNavigationLabel(), $labels);
            foreach ($hidden as $resource) {
                $this->assertFalse($resource::shouldRegisterNavigation(), "{$resource} must not register navigation");
                $this->assertNotContains($resource::getNavigationLabel(), $labels, "{$resource} must not be in the {$locale} sidebar");
                // "Stores" stays the highlighted sidebar entry on these pages.
                $this->assertContains($resource::getRouteBaseName().'.*', (array) StoreResource::getNavigationItemActiveRoutePattern());
                // Contextual pages stay reachable for authorised staff.
                $this->get($resource::getUrl('index'))->assertOk();
            }
        }

        // …and stay protected: no catalog permission, no access.
        $this->actingAsAdmin($this->employee(['orders.view']));
        foreach ($hidden as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_catalog_staff_reach_categories_and_ratings_from_the_stores_page(): void
    {
        $this->actingAsAdmin($this->employee(['catalog.view', 'catalog.manage', 'ratings.moderate']));
        Livewire::test(ListStores::class)
            ->assertActionVisible('storeCategories')->assertActionVisible('productCategories')->assertActionVisible('ratings');

        $this->actingAsAdmin($this->employee(['catalog.view']));
        Livewire::test(ListStores::class)->assertActionHidden('ratings');
    }

    public function test_new_store_products_and_branches_open_their_pricing_next(): void
    {
        $store = $this->store();
        $city = $this->city();
        $category = Category::create(['name' => ['ar' => 'مشاوي', 'en' => 'Grills'], 'slug' => 'grills-x']);
        $this->actingAsAdmin($this->superAdmin());

        $product = Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->callAction(TestAction::make('create')->table(), ['name' => ['ar' => 'كبة', 'en' => 'Kibbeh'], 'categories' => [$category->id], 'is_active' => true]);
        $created = Product::query()->where('store_id', $store->id)->sole();
        $product->assertRedirect(ProductResource::getUrl('edit', ['record' => $created]).'?relation=branch-prices');

        $branch = Livewire::test(BranchesRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->callAction(TestAction::make('create')->table(), [
                'city_id' => $city->id, 'name' => ['ar' => 'فرع', 'en' => 'Branch'], 'address' => ['ar' => 'عنوان', 'en' => 'Address'],
                'lat' => 33.5, 'lng' => 36.3, 'is_active' => true, 'is_accepting_orders' => true,
            ]);
        $branch->assertRedirect(BranchResource::getUrl('edit', ['record' => Branch::query()->where('store_id', $store->id)->sole()]).'?relation=products');
    }

    public function test_a_new_category_gets_its_url_name_from_the_english_name(): void
    {
        StoreCategory::create(['name' => ['ar' => 'حلويات', 'en' => 'Sweets'], 'slug' => 'sweets']);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageStoreCategories::class)
            ->callAction('create', ['name' => ['ar' => 'حلويات شرقية', 'en' => 'Sweets'], 'slug' => null])
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('store_categories', ['slug' => 'sweets-2']);
    }

    public function test_dashboard_links_open_the_right_tab(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        Livewire::withQueryParams(['tab' => 'pending'])->test(ListOrders::class)
            ->assertSet('activeTab', 'pending');
        Livewire::withQueryParams(['tab' => 'approved'])->test(ListPlusRequests::class)->assertSet('activeTab', 'approved');
        $this->assertStringContainsString('tab=pending', OperationsOverview::items()[0]['url']);
    }
}
