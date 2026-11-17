<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\OrderPayments\Pages\ListOrderPayments;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-16, Phase 2) — every list shows its filters above the
 * table (open on wide screens / when active, behind a button on phones),
 * with a reset link, and filters apply immediately.
 */
class TableFiltersLayoutTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_lists_use_the_visible_filter_layout_and_the_opener_is_loaded(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        foreach ([ListOrders::class, ListCustomers::class] as $page) {
            $table = Livewire::test($page)->instance()->getTable();
            $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout(), $page);
            $this->assertFalse($table->hasDeferredFilters(), $page);
        }

        $this->get('/admin/customers')->assertOk()
            ->assertSee('wfFiltersInit', false)
            ->assertSee('fi-ta-filters-above-content-ctn', false);
    }

    public function test_date_range_filter_keeps_its_query_shows_chips_and_sits_side_by_side(): void
    {
        // These edges are UTC days; local-day behaviour: AdminTimezoneTest.
        config(['waffrli.admin_display_timezone' => 'UTC']);
        $this->actingAsAdmin($this->superAdmin());
        $branch = $this->branch($this->store(), $this->city());
        $customer = $this->customer();
        $orders = [];
        foreach (['2026-11-01 10:00:00', '2026-11-05 23:59:00', '2026-11-06 00:00:00', '2026-11-09 12:00:00'] as $at) {
            $order = $this->order($customer, $branch, '5');
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
            $orders[$at] = Order::find($order->id);
        }

        $page = Livewire::test(ListOrders::class)
            ->filterTable('created_between', ['from' => '2026-11-05', 'until' => '2026-11-06'])
            // Both edge days included (the admin's days; UTC here).
            ->assertCanSeeTableRecords([$orders['2026-11-05 23:59:00'], $orders['2026-11-06 00:00:00']])
            ->assertCanNotSeeTableRecords([$orders['2026-11-01 10:00:00'], $orders['2026-11-09 12:00:00']]);

        // One removable chip per filled date (the active-filter count includes dates now).
        $table = $page->instance()->getTable();
        $indicators = $table->getFilter('created_between')->getIndicators();
        $this->assertCount(2, $indicators);
        $this->assertSame(['default' => 2], $table->getFilter('created_between')->getColumnSpan());
        $this->assertSame(['default' => 2, 'lg' => 4, 'xl' => 5], $table->getFiltersFormColumns());

        // Only "from": one chip, open-ended range.
        Livewire::test(ListOrders::class)->filterTable('created_between', ['from' => '2026-11-06'])
            ->assertCanSeeTableRecords([$orders['2026-11-06 00:00:00'], $orders['2026-11-09 12:00:00']])
            ->assertCanNotSeeTableRecords([$orders['2026-11-05 23:59:00']]);

        // Payments keep filtering on paid_at.
        Livewire::test(ListOrderPayments::class)->filterTable('paid_between', ['from' => '2026-11-05'])->assertSuccessful();
    }
}
