<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Models\City;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * WAFFRLI (2026-11-16, Phase 3) — the map helpers in admin forms render and
 * are never saved: the real latitude / longitude / radius fields keep their
 * own validation and values.
 */
class AdminFormsMapPickerTest extends AdminPanelTestCase
{
    /** Rendering of the map itself is verified in the browser (docs/waffrli/admin-panel-audit.md). */
    public function test_city_form_with_the_map_saves_only_real_fields(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageCities::class)
            ->mountAction(TestAction::make('create'))
            ->setActionData([
                'name' => ['ar' => 'حماة', 'en' => 'Hama'],
                'center_lat' => 35.1318, 'center_lng' => 36.7578, 'radius_km' => 8,
                'sort_order' => 1, 'is_active' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $city = City::query()->where('name->en', 'Hama')->sole();
        $this->assertEqualsWithDelta(35.1318, (float) $city->center_lat, 0.00001);
        $this->assertSame(8.0, (float) $city->radius_km);
        $this->assertArrayNotHasKey('map_picker', $city->getAttributes());
    }

    public function test_out_of_range_coordinates_are_still_rejected(): void
    {
        $this->actingAsAdmin($this->superAdmin());

        Livewire::test(ManageCities::class)
            ->callAction(TestAction::make('create'), [
                'name' => ['ar' => 'س', 'en' => 'X'], 'center_lat' => 95, 'center_lng' => 36.7, 'radius_km' => 5,
            ])
            ->assertHasActionErrors(['center_lat']);
    }
}
