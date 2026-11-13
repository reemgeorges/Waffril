<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\ProductRatings\Pages\ListProductRatings;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\RatingsRelationManager as ProductRatingsRelationManager;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\Stores\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\Avatar;
use App\Filament\Support\Fields;
use App\Models\AdminAuditLog;
use App\Models\BranchProduct;
use App\Models\ProductRating;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * Table UX overhaul (2026-11-12): the shared table conventions, the Stores
 * reference layout, contextual columns, search and filters on real fields,
 * and the explicit row actions (with their permission checks and audit).
 * Display behaviour only; business rules have their own tests.
 */
class AdminTableUxTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_every_table_shares_the_mobile_filter_and_pagination_conventions(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        $table = Livewire::test(ListOrders::class)->instance()->getTable();
        $this->assertTrue($table->isStackedOnMobile(), 'rows stack into cards on phones');
        $this->assertFalse($table->hasDeferredFilters(), 'filters apply as soon as they change');
        $this->assertSame(25, $table->getDefaultPaginationPageOption());
        $this->assertSame([10, 25, 50, 100], $table->getPaginationPageOptions());
    }

    public function test_stores_are_cards_with_status_count_links_and_a_primary_manage_action(): void
    {
        $store = $this->store();
        $this->branch($store, $this->city());
        $this->actingAsAdmin($this->superAdmin());

        $page = Livewire::test(ListStores::class)
            ->assertCanSeeTableRecords([$store])
            ->assertSee(__('admin.table.manage_store'))
            ->assertSee(__('admin.table.active'))
            ->assertSee(trans_choice('admin.table.branches_count', 1, ['count' => 1]));
        $this->assertNotEmpty($page->instance()->getTable()->getContentGrid(), 'stores render as a card grid');

        // The branch count opens this store's own Branches tab.
        $this->assertSame(
            StoreResource::getUrl('edit', ['record' => $store]).'?relation=branches',
            $page->instance()->getTable()->getColumn('branches_count')->record($store->loadCount('branches'))->getUrl(),
        );
    }

    public function test_deactivate_and_activate_are_confirmed_row_actions_that_respect_permissions(): void
    {
        $store = $this->store();
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ListStores::class)
            ->assertTableActionHidden('activate', $store)
            ->callTableAction('deactivate', $store);
        $this->assertFalse($store->fresh()->is_active);

        Livewire::test(ListStores::class)->callTableAction('activate', $store);
        $this->assertTrue($store->fresh()->is_active);

        // Without catalog.manage the switch is not offered at all.
        $this->actingAsAdmin($this->employee(['catalog.view']));
        Livewire::test(ListStores::class)
            ->assertTableActionHidden('deactivate', $store)
            ->assertTableActionHidden('activate', $store);
    }

    public function test_pausing_a_branch_from_its_store_card_uses_the_audited_service(): void
    {
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(BranchesRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->callTableAction('pause_orders', $branch);

        $this->assertFalse($branch->fresh()->is_accepting_orders);
        $this->assertTrue(AdminAuditLog::query()->where('action', 'catalog.branch_accepting_orders')->where('auditable_id', $branch->id)->exists());
    }

    public function test_store_products_can_be_filtered_by_branch_pricing(): void
    {
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $priced = $this->product($store);
        $unpriced = $this->product($store);
        BranchProduct::query()->firstOrCreate(['branch_id' => $branch->id, 'product_id' => $priced->id], ['price' => '5.0000', 'is_available' => true]);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $store, 'pageClass' => EditStore::class])
            ->assertCanSeeTableRecords([$priced, $unpriced])
            ->filterTable('priced', false)
            ->assertCanSeeTableRecords([$unpriced])->assertCanNotSeeTableRecords([$priced])
            ->assertSee(__('admin.table.not_priced'));
    }

    public function test_search_finds_customers_and_orders_by_phone(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $order = $this->order($customer, $this->branch($this->store(), $this->city()), '5');
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ListCustomers::class)->searchTable($customer->phone)
            ->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ListOrders::class)->searchTable($customer->phone)
            ->assertCanSeeTableRecords([$order]);
    }

    public function test_ratings_hide_the_column_of_the_page_they_sit_on_and_filter_by_score(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $customer = $this->customer();
        $order = $this->order($customer, $this->branch($store, $this->city()), '5');
        $five = ProductRating::query()->create(['user_id' => $customer->id, 'product_id' => $product->id, 'order_id' => $order->id, 'rating' => 5, 'comment' => 'Great']);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ListProductRatings::class)
            ->assertTableColumnVisible('product.name')
            ->filterTable('rating', [5])->assertCanSeeTableRecords([$five])
            ->filterTable('rating', [1])->assertCanNotSeeTableRecords([$five]);

        Livewire::test(ProductRatingsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertCanSeeTableRecords([$five])
            ->assertTableColumnHidden('product.name');
    }

    public function test_an_orders_payments_tab_does_not_repeat_the_order(): void
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->city()), '5');
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertTableColumnHidden('order.order_number');
    }

    public function test_placeholders_and_directional_values_are_display_safe(): void
    {
        // An Arabic name skips the article "ال" for its initial.
        $svg = base64_decode(substr(Avatar::placeholder('السوق الطازج'), strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>س</text>', $svg);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', Avatar::placeholder(null));

        // Phones keep their reading order inside Arabic text.
        $this->assertSame("\u{2066}+963912345678\u{2069}", Fields::ltr('+963912345678'));
        $this->assertNull(Fields::ltr(null));
    }
}
