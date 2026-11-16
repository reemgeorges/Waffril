<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Orders\Pages\ListOrders;
use Filament\Tables\Enums\FiltersLayout;
use Livewire\Livewire;

/**
 * WAFFRLI (2026-11-16, Phase 2) — every list shows its filters above the
 * table (open on wide screens / when active, behind a button on phones),
 * with a reset link, and filters apply immediately.
 */
class TableFiltersLayoutTest extends AdminPanelTestCase
{
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
}
