<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\DriverMapPage;
use App\Models\City;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Order\OrderAdminService;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Database\Seeders\WaffrliDemoSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * WAFFRLI (2026-11-01) — the Filament driver map on the seeded demo data:
 * only drivers with an assigned / picked-up order, filters, stale positions,
 * permission enforcement on every refresh and escaping of stored text.
 */
class DriverMapTest extends AdminPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(WaffrliDemoSeeder::class);
    }

    /** @return list<string> */
    protected function names(array $markers): array
    {
        return collect($markers)->pluck('name')->sort()->values()->all();
    }

    protected function demoDriverName(int $n): string
    {
        return DemoPeopleSeeder::DRIVERS[$n][0];
    }

    public function test_only_drivers_with_active_deliveries_are_shown(): void
    {
        $this->actingAsAdmin($this->employee(['drivers.track']));

        $markers = Livewire::test(DriverMapPage::class)->instance()->markers();
        $expected = collect([1, 2, 3, 4, 7])->map(fn ($n) => $this->demoDriverName($n))->sort()->values()->all();
        $this->assertSame($expected, $this->names($markers));

        // Idle (5, 8) and offline (6) drivers are not on the map; each marker has a stored position.
        foreach ($markers as $marker) {
            $this->assertNotNull($marker['lat']);
            $this->assertNotEmpty($marker['orders']);
            $this->assertNotNull($marker['updated_at']);
        }

        $stale = collect($markers)->where('stale', true)->pluck('name')->all();
        $this->assertSame([$this->demoDriverName(4)], $stale, 'Only the position older than the threshold is stale.');
    }

    public function test_city_and_status_filters(): void
    {
        $this->actingAsAdmin($this->employee(['drivers.track']));
        $aleppo = City::query()->where('name->en', 'Aleppo')->firstOrFail();

        $page = Livewire::test(DriverMapPage::class)->set('cityId', $aleppo->id)->assertDispatched('driver-map-updated');
        $this->assertSame([$this->demoDriverName(7)], $this->names($page->instance()->markers()));

        $page->set('cityId', null)->set('status', 'picked_up');
        $this->assertSame(collect([3, 4])->map(fn ($n) => $this->demoDriverName($n))->sort()->values()->all(), $this->names($page->instance()->markers()));

        $page->set('cityId', $aleppo->id);
        $this->assertSame([], $page->instance()->markers());

        $page->set('status', 'not-a-status')->set('cityId', null);
        $this->assertCount(5, $page->instance()->markers(), 'An unknown status falls back to all active statuses.');
    }

    public function test_driver_leaves_the_map_when_the_delivery_ends(): void
    {
        $this->actingAsAdmin($this->employee(['drivers.track']));
        $driver = DemoPeopleSeeder::driver(3);
        $order = Order::query()->where('driver_id', $driver->id)->where('status', 'picked_up')->firstOrFail();

        $page = Livewire::test(DriverMapPage::class);
        $this->assertContains($driver->name, $this->names($page->instance()->markers()));

        app(OrderAdminService::class)->markDelivered($order, $driver);

        $page->call('refreshMap')->assertDispatched('driver-map-updated', fn ($name, $params) => ! collect($params['markers'])->contains('name', $driver->name));
        $this->assertNotContains($driver->name, $this->names($page->instance()->markers()));
    }

    public function test_tracking_requires_the_drivers_track_permission_on_every_request(): void
    {
        $this->actingAsAdmin($this->employee(['drivers.view']));
        $this->get(DriverMapPage::getUrl())->assertForbidden();
        Livewire::test(DriverMapPage::class)->assertForbidden();

        $tracker = $this->employee(['drivers.track']);
        $this->actingAsAdmin($tracker);
        $page = Livewire::test(DriverMapPage::class)->assertSuccessful();

        $tracker->revokePermissionTo('drivers.track');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $page->call('refreshMap')->assertForbidden();
    }

    public function test_page_renders_leaflet_tiles_attribution_and_escapes_stored_text(): void
    {
        $this->actingAsAdmin($this->employee(['drivers.track']));
        $driver = DemoPeopleSeeder::driver(1);
        $driver->forceFill(['name' => '<img src=x onerror=alert(1)>'])->save();

        // URLs inside @js() payloads are JSON-escaped (\/); normalise before searching.
        $html = str_replace('\\/', '/', $this->get(DriverMapPage::getUrl())->assertOk()->getContent());

        $this->assertStringContainsString('vendor/leaflet/leaflet.js', $html);
        $this->assertStringContainsString('tile.openstreetmap.org', $html);
        $this->assertStringContainsString('OpenStreetMap', $html);
        $this->assertStringContainsString('DEMO-0031', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertFileExists(public_path('vendor/leaflet/leaflet.js'));
        $this->assertFileExists(public_path('vendor/leaflet/leaflet.css'));
    }
}
