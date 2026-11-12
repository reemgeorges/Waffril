<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Widgets\FinanceOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\OrdersTrendChart;
use App\Filament\Widgets\PendingOrdersTable;
use App\Filament\Widgets\TodayOverview;
use App\Models\Order;
use App\Models\StoreCategory;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * UX redesign (2026-11-11): dashboard hierarchy, order work tabs, store
 * workflows and permission-aware visibility of the new shortcuts. Display
 * only — business rules are covered by their own tests.
 */
class AdminUxRedesignTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    /** @return array{0: Order, 1: Order, 2: Order} pending, urgent pending, delivered */
    protected function orders(): array
    {
        $branch = $this->branch($this->store(), $this->city());
        $customer = $this->customer();
        $pending = $this->order($customer, $branch, '5');
        $urgent = $this->order($customer, $branch, '6', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->addMinutes(20)]);
        $urgent->forceFill(['urgent_at' => now()])->save();
        $delivered = $this->order($customer, $branch, '7');
        $delivered->forceFill(['status' => OrderStatus::Delivered->value, 'delivered_at' => now()])->save();

        return [$pending, $urgent, $delivered];
    }

    public function test_dashboard_renders_every_widget_with_real_counts(): void
    {
        $this->orders();
        $this->actingAsAdmin($this->superAdmin());

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee(__('admin.dashboard.action_pending_orders'));

        $items = collect(OperationsOverview::items())->keyBy('key');
        $this->assertSame(2, $items['pending_orders']['count']);
        $this->assertSame(1, $items['urgent_orders']['count']);
        $this->assertStringContainsString('tab=pending', $items['pending_orders']['url']);

        Livewire::test(OperationsOverview::class)
            ->assertSee(__('admin.dashboard.attention_title'))
            ->assertSee(__('admin.dashboard.needs_action'))
            ->assertSee(__('admin.dashboard.urgent_orders'));

        $today = TodayOverview::figures();
        $this->assertSame(3, $today['received']);
        $this->assertSame(1, $today['delivered']);
        $this->assertSame('USD 7.00', $today['sales'][0]['amount']);
        Livewire::test(TodayOverview::class)->assertSee('USD 7.00');

        $series = OrdersTrendChart::series();
        $this->assertCount(OrdersTrendChart::DAYS, $series);
        $this->assertSame(['delivered' => 1, 'cancelled' => 0, 'open' => 2], end($series));
        Livewire::test(OrdersTrendChart::class)->assertSuccessful();

        Livewire::test(OrdersByStatusChart::class)->assertSee(OrderStatus::Pending->label());
        Livewire::test(PendingOrdersTable::class)->assertSee(__('admin.orders.urgent'));
        Livewire::test(FinanceOverview::class)->assertSuccessful();
    }

    public function test_trend_chart_shows_an_empty_state_without_orders(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(OrdersTrendChart::class)->assertSee(__('admin.dashboard.trend_empty'));
        Livewire::test(OperationsOverview::class)->assertSee(__('admin.dashboard.attention_clear'));
    }

    public function test_attention_tiles_follow_permissions(): void
    {
        $this->orders();
        $this->actingAsAdmin($this->employee(['plus.view']));

        $this->assertSame(['pending_plus_requests'], array_column(OperationsOverview::items(), 'key'));
        $this->assertFalse(TodayOverview::canView());
        $this->assertFalse(PendingOrdersTable::canView());

        $this->actingAsAdmin($this->employee(['catalog.view']));
        $this->assertSame([], OperationsOverview::items());
        $this->assertFalse(OperationsOverview::canView());
    }

    public function test_order_tabs_filter_by_next_step(): void
    {
        [$pending, $urgent, $delivered] = $this->orders();
        $this->actingAsAdmin($this->superAdmin());

        $tabs = (new ListOrders)->getTabs();
        foreach (['all', 'pending', 'urgent', 'on_the_road', 'delivered', 'cancelled'] as $key) {
            $this->assertArrayHasKey($key, $tabs);
        }

        Livewire::test(ListOrders::class)->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$urgent, $pending], inOrder: true)
            ->assertCanNotSeeTableRecords([$delivered]);
        Livewire::test(ListOrders::class)->set('activeTab', 'urgent')
            ->assertCanSeeTableRecords([$urgent])->assertCanNotSeeTableRecords([$pending]);
        Livewire::test(ListOrders::class)->set('activeTab', 'delivered')
            ->assertCanSeeTableRecords([$delivered])->assertCanNotSeeTableRecords([$pending, $urgent]);
    }

    public function test_store_form_fills_the_slug_and_offers_category_creation_by_permission(): void
    {
        $category = StoreCategory::create(['name' => ['ar' => 'مطاعم', 'en' => 'Restaurants'], 'slug' => 'restaurants']);
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(CreateStore::class)
            ->fillForm(['name' => ['ar' => 'متجر', 'en' => 'Corner Shop']])
            ->assertSchemaStateSet(['slug' => 'corner-shop'])
            ->fillForm(['categories' => [$category->id], 'plus_discount_percent' => 0])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('stores', ['slug' => 'corner-shop']);

        // Quick-create of a store category is offered with the category permission.
        $this->assertTrue(StoreCategoryResource::canCreate());
        $this->actingAsAdmin($this->employee(['catalog.view']));
        $this->assertFalse(StoreCategoryResource::canCreate());
    }

    public function test_store_edit_page_combines_details_with_branches_and_products(): void
    {
        $store = $this->store();
        $this->actingAsAdmin($this->superAdmin());

        $page = Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])->assertOk()
            ->assertSee(__('admin.stores.section_info'))
            ->assertSee(__('admin.resources.branch.plural'))
            ->assertSee(__('admin.resources.product.plural'));
        $this->assertTrue($page->instance()->hasCombinedRelationManagerTabsWithContent());

        Livewire::test(ManageStoreCategories::class)->assertOk();
        $this->get(StoreResource::getUrl('index'))->assertOk();
    }
}
