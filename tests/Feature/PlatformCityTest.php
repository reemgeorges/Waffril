<?php

namespace Tests\Feature;

use App\Models\City;
use App\Support\Auth\PlatformPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — admin city management, Arabic/English
 * output, and customer GPS city detection.
 */
class PlatformCityTest extends TestCase
{
    use PlatformTestHelpers, RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->adminToken([PlatformPermissions::CITIES_MANAGE]);
    }

    protected function createCity(array $overrides = [])
    {
        return $this->asBearer($this->token)->postJson('/api/v1/admin/cities/create', array_merge([
            'name' => ['ar' => 'دمشق', 'en' => 'Damascus'],
            'center_lat' => 33.5138,
            'center_lng' => 36.2765,
            'radius_km' => 25,
        ], $overrides));
    }

    public function test_admin_creates_updates_and_deactivates_a_city(): void
    {
        $this->createCity()->assertOk()->assertJsonPath('data.city.name_translations.en', 'Damascus');
        $city = City::firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/cities/update', ['id' => $city->id, 'radius_km' => 30])->assertOk();
        $this->assertSame('30.00', (string) $city->fresh()->radius_km);

        $this->asBearer($this->token)->postJson('/api/v1/admin/cities/update', ['id' => $city->id, 'is_active' => false])
            ->assertStatus(400);

        $this->asBearer($this->token)->postJson('/api/v1/admin/cities/deactivate', ['id' => $city->id])->assertOk();
        $this->assertFalse($city->fresh()->is_active);
    }

    public function test_city_validation(): void
    {
        $this->createCity(['name' => ['en' => 'Only English']])->assertStatus(400);
        $this->createCity(['center_lat' => 95])->assertStatus(400);
        $this->createCity(['radius_km' => 0])->assertStatus(400);
        $this->assertSame(0, City::count());
    }

    public function test_accept_language_selects_the_translation(): void
    {
        $this->createCity()->assertOk();
        $user = $this->userToken();

        $this->asBearer($user)->withHeader('Accept-Language', 'ar')->getJson('/api/v1/user/cities')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.cities.0.name', 'دمشق');

        $this->asBearer($user)->withHeader('Accept-Language', 'en-US,en;q=0.9')->getJson('/api/v1/user/cities')
            ->assertOk()
            ->assertJsonPath('data.cities.0.name', 'Damascus');
    }

    public function test_customer_list_only_shows_active_cities(): void
    {
        $this->createCity()->assertOk();
        $this->createCity(['name' => ['ar' => 'حلب', 'en' => 'Aleppo'], 'center_lat' => 36.2021, 'center_lng' => 37.1343])->assertOk();
        $aleppo = City::all()->first(fn ($c) => $c->getTranslation('name', 'en') === 'Aleppo');
        $this->asBearer($this->token)->postJson('/api/v1/admin/cities/deactivate', ['id' => $aleppo->id])->assertOk();

        $this->asBearer($this->userToken())->getJson('/api/v1/user/cities')
            ->assertOk()
            ->assertJsonCount(1, 'data.cities');
    }

    public function test_gps_detection_picks_the_nearest_active_city_within_radius(): void
    {
        $this->createCity()->assertOk();
        $this->createCity(['name' => ['ar' => 'حلب', 'en' => 'Aleppo'], 'center_lat' => 36.2021, 'center_lng' => 37.1343])->assertOk();
        $user = $this->userToken();

        $this->asBearer($user)->postJson('/api/v1/user/cities/detect', ['lat' => 33.52, 'lng' => 36.28])
            ->assertOk()
            ->assertJsonPath('data.city.name_translations.en', 'Damascus')
            ->assertJsonPath('data.distance_method', 'haversine');

        $this->asBearer($user)->postJson('/api/v1/user/cities/detect', ['lat' => 35.0, 'lng' => 36.0])
            ->assertStatus(404)
            ->assertJsonPath('status', false);

        $this->asBearer($user)->postJson('/api/v1/user/cities/detect', ['lat' => 'x'])->assertStatus(400);
    }
}
