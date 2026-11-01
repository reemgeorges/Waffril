<?php

namespace Tests\Feature;

use App\Contracts\Delivery\DistanceCalculator;
use App\Models\City;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Services\Functional\Delivery\DeliveryFeeResolver;
use App\Services\Functional\Delivery\DeliveryQuote;
use App\Support\Auth\PlatformPermissions;
use App\Support\Geo\Polygon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — platform-wide fee rules: band overlap,
 * scope precedence (most specific scope is authoritative), coverage,
 * fee arithmetic and a replaceable distance strategy.
 *
 * Geometry: origin (33.5, 36.3); destination (33.545, 36.3) is 5.004 km
 * away along the meridian (GeoService earth radius 6371 km).
 */
class PlatformDeliveryFeeRuleTest extends TestCase
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
            'center_lat' => 33.5, 'center_lng' => 36.3, 'radius_km' => 25,
        ]);
    }

    protected function createRule(array $data)
    {
        return $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-fee-rules/create', $data);
    }

    protected function createArea(float $minLat, float $minLng, float $maxLat, float $maxLng): DeliveryArea
    {
        $boundary = $this->squareBoundary($minLat, $minLng, $maxLat, $maxLng);

        return DeliveryArea::create(array_merge([
            'city_id' => $this->city->id,
            'name' => ['ar' => 'منطقة', 'en' => 'Area'],
            'boundary' => $boundary,
        ], Polygon::boundingBox($boundary)));
    }

    protected function quote(float $destLat = 33.545, float $destLng = 36.3): DeliveryQuote
    {
        return app(DeliveryFeeResolver::class)->quote($this->city->id, 33.5, 36.3, $destLat, $destLng);
    }

    public function test_overlapping_active_bands_in_one_scope_are_rejected_adjacent_bands_allowed(): void
    {
        $base = ['city_id' => $this->city->id, 'base_fee' => '1000', 'per_km_fee' => '0'];

        $this->createRule($base + ['distance_from_km' => 0, 'distance_to_km' => 5])->assertOk()
            ->assertJsonPath('data.delivery_fee_rule.scope', 'city');
        $this->createRule($base + ['distance_from_km' => 5, 'distance_to_km' => 10])->assertOk();
        $this->createRule($base + ['distance_from_km' => 4, 'distance_to_km' => 6])->assertStatus(422);

        // Same band in a different scope (global) is fine.
        $this->createRule(['distance_from_km' => 4, 'distance_to_km' => 6, 'base_fee' => '1', 'per_km_fee' => '0'])->assertOk()
            ->assertJsonPath('data.delivery_fee_rule.scope', 'global');

        $this->assertSame(3, DeliveryFeeRule::count());
    }

    public function test_inactive_band_cannot_be_activated_into_an_overlap(): void
    {
        $base = ['city_id' => $this->city->id, 'base_fee' => '1', 'per_km_fee' => '0'];
        $this->createRule($base + ['distance_from_km' => 0, 'distance_to_km' => 5])->assertOk();
        $this->createRule($base + ['distance_from_km' => 3, 'distance_to_km' => 8, 'is_active' => false])->assertOk();
        $inactive = DeliveryFeeRule::where('is_active', false)->firstOrFail();

        $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-fee-rules/activate', ['id' => $inactive->id])
            ->assertStatus(422);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_area_rules_must_name_the_areas_own_city_and_band_must_be_valid(): void
    {
        $area = $this->createArea(33.53, 36.28, 33.56, 36.32);
        $other = City::create(['name' => ['ar' => 'حلب', 'en' => 'Aleppo'], 'center_lat' => 36.2, 'center_lng' => 37.1, 'radius_km' => 20]);

        $this->createRule(['city_id' => $other->id, 'delivery_area_id' => $area->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => 1, 'per_km_fee' => 0])
            ->assertStatus(422);
        $this->createRule(['delivery_area_id' => $area->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => 1, 'per_km_fee' => 0])
            ->assertStatus(400);
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 5, 'distance_to_km' => 5, 'base_fee' => 1, 'per_km_fee' => 0])
            ->assertStatus(400);
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => '1.00001', 'per_km_fee' => 0])
            ->assertStatus(400);

        $this->assertSame(0, DeliveryFeeRule::count());
    }

    public function test_fee_is_base_plus_per_km_beyond_band_start(): void
    {
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => '1000', 'per_km_fee' => '0'])->assertOk();
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 5, 'distance_to_km' => 10, 'base_fee' => '1000', 'per_km_fee' => '200'])->assertOk();

        $quote = $this->quote();

        $this->assertTrue($quote->covered);
        $this->assertSame(5.004, $quote->distanceKm);
        $this->assertSame('haversine', $quote->distanceMethod);
        $this->assertSame('city', $quote->ruleScope);
        $this->assertSame('1000.8000', $quote->fee);
    }

    public function test_band_upper_bound_is_exclusive_and_unmatched_distance_is_not_covered(): void
    {
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => '1000', 'per_km_fee' => '0'])->assertOk();

        $quote = $this->quote();
        $this->assertFalse($quote->covered);
        $this->assertSame(DeliveryQuote::REASON_NO_MATCHING_RULE, $quote->reason);
        $this->assertNull($quote->fee);
    }

    public function test_most_specific_scope_with_rules_is_authoritative(): void
    {
        $area = $this->createArea(33.53, 36.28, 33.56, 36.32);

        // Global and city rules would cover 5.004 km ...
        $this->createRule(['distance_from_km' => 0, 'distance_to_km' => 50, 'base_fee' => '9', 'per_km_fee' => '0'])->assertOk();
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 50, 'base_fee' => '7', 'per_km_fee' => '0'])->assertOk();
        // ... but the area's own rules stop at 3 km, so it is NOT covered.
        $this->createRule(['city_id' => $this->city->id, 'delivery_area_id' => $area->id, 'distance_from_km' => 0, 'distance_to_km' => 3, 'base_fee' => '5', 'per_km_fee' => '0'])->assertOk();

        $this->assertFalse($this->quote()->covered);

        $this->createRule(['city_id' => $this->city->id, 'delivery_area_id' => $area->id, 'distance_from_km' => 3, 'distance_to_km' => 8, 'base_fee' => '500', 'per_km_fee' => '0'])->assertOk();
        $quote = $this->quote();
        $this->assertTrue($quote->covered);
        $this->assertSame('area', $quote->ruleScope);
        $this->assertSame($area->id, $quote->deliveryAreaId);
        $this->assertSame('500.0000', $quote->fee);

        // With the area's rules deactivated, the city scope takes over.
        DeliveryFeeRule::where('delivery_area_id', $area->id)->update(['is_active' => false]);
        $this->assertSame('7.0000', $this->quote()->fee);

        // With no city rules either, the global scope applies.
        DeliveryFeeRule::where('scope_key', 'c:'.$this->city->id)->update(['is_active' => false]);
        $this->assertSame('global', $this->quote()->ruleScope);
    }

    public function test_destination_outside_every_active_area_is_not_covered(): void
    {
        $area = $this->createArea(33.53, 36.28, 33.56, 36.32);
        $this->createRule(['distance_from_km' => 0, 'distance_to_km' => 50, 'base_fee' => '9', 'per_km_fee' => '0'])->assertOk();

        $outside = $this->quote(33.60, 36.30);
        $this->assertFalse($outside->covered);
        $this->assertSame(DeliveryQuote::REASON_OUTSIDE_SERVICE_AREAS, $outside->reason);

        // A city with no active areas has no area restriction.
        $area->update(['is_active' => false]);
        $this->assertTrue($this->quote(33.60, 36.30)->covered);
    }

    public function test_distance_strategy_is_replaceable_by_configuration(): void
    {
        config([
            'delivery.distance_strategies.fixed' => FixedTwoKmDistanceCalculator::class,
            'delivery.distance_strategy' => 'fixed',
        ]);
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => '10', 'per_km_fee' => '1.5'])->assertOk();

        $quote = $this->quote();

        $this->assertSame('fixed-test', $quote->distanceMethod);
        $this->assertSame(2.0, $quote->distanceKm);
        $this->assertSame('13.0000', $quote->fee);
    }

    public function test_quote_preview_endpoint_returns_the_envelope(): void
    {
        $this->createRule(['city_id' => $this->city->id, 'distance_from_km' => 0, 'distance_to_km' => 10, 'base_fee' => '1000', 'per_km_fee' => '0'])->assertOk();

        $this->asBearer($this->token)->postJson('/api/v1/admin/delivery-fee-rules/quote', [
            'city_id' => $this->city->id,
            'origin_lat' => 33.5, 'origin_lng' => 36.3,
            'destination_lat' => 33.545, 'destination_lng' => 36.3,
        ])->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.quote.covered', true)
            ->assertJsonPath('data.quote.fee', '1000.0000')
            ->assertJsonPath('data.quote.distance_method', 'haversine');
    }
}

class FixedTwoKmDistanceCalculator implements DistanceCalculator
{
    public function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        return 2.0;
    }

    public function method(): string
    {
        return 'fixed-test';
    }
}
