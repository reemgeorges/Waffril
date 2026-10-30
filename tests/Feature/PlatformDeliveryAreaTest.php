<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\DeliveryArea;
use App\Support\Auth\PlatformPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — platform-wide delivery areas: GeoJSON
 * validation and server-derived bounding boxes.
 */
class PlatformDeliveryAreaTest extends TestCase
{
    use PlatformTestHelpers, RefreshDatabase;

    protected string $token;

    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->adminToken([PlatformPermissions::DELIVERY_MANAGE]);
        $this->city = City::create([
            'name' => ['ar' => 'دمشق', 'en' => 'Damascus'],
            'center_lat' => 33.5138, 'center_lng' => 36.2765, 'radius_km' => 25,
        ]);
    }

    protected function createArea(array $overrides = [])
    {
        return $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-areas/create', array_merge([
            'city_id' => $this->city->id,
            'name' => ['ar' => 'المزة', 'en' => 'Mezzeh'],
            'boundary' => $this->squareBoundary(33.50, 36.20, 33.52, 36.25),
        ], $overrides));
    }

    public function test_area_is_created_with_a_derived_bounding_box(): void
    {
        $this->createArea()->assertOk()->assertJsonPath('data.delivery_area.name_translations.en', 'Mezzeh');

        $area = DeliveryArea::firstOrFail();
        $this->assertSame('33.5000000', (string) $area->min_lat);
        $this->assertSame('33.5200000', (string) $area->max_lat);
        $this->assertSame('36.2000000', (string) $area->min_lng);
        $this->assertSame('36.2500000', (string) $area->max_lng);
    }

    public function test_invalid_boundaries_and_client_bounding_boxes_are_rejected(): void
    {
        $open = $this->squareBoundary(33.50, 36.20, 33.52, 36.25);
        array_pop($open['coordinates'][0]);
        $open['coordinates'][0][] = [36.21, 33.51];

        $this->createArea(['boundary' => $open])->assertStatus(400);
        $this->createArea(['boundary' => ['type' => 'Point', 'coordinates' => [36.2, 33.5]]])->assertStatus(400);
        $this->createArea(['boundary' => ['type' => 'Polygon', 'coordinates' => [[[36.2, 33.5], [36.3, 33.5], [36.2, 33.5]]]]])->assertStatus(400);
        $this->createArea(['min_lat' => 0])->assertStatus(400);
        $this->createArea(['city_id' => 999])->assertStatus(400);

        $this->assertSame(0, DeliveryArea::count());
    }

    public function test_updating_the_boundary_recomputes_the_bounding_box(): void
    {
        $this->createArea()->assertOk();
        $area = DeliveryArea::firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-areas/update', [
            'id' => $area->id,
            'boundary' => $this->squareBoundary(33.40, 36.10, 33.45, 36.15),
        ])->assertOk();

        $area->refresh();
        $this->assertSame('33.4000000', (string) $area->min_lat);
        $this->assertSame('36.1500000', (string) $area->max_lng);

        $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-areas/deactivate', ['id' => $area->id])->assertOk();
        $this->assertFalse($area->fresh()->is_active);
    }
}
