<?php

namespace App\Services\Functional\Delivery;

use App\Contracts\Delivery\DistanceCalculator;
use App\Services\Functional\Geo\GeoService;

/**
 * WAFFRLI P0 (2026-10-30) — provisional straight-line distance, reusing
 * the existing GeoService great-circle formula.
 */
class HaversineDistanceCalculator implements DistanceCalculator
{
    public function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        return round(GeoService::distanceInMeters($fromLat, $fromLng, $toLat, $toLng) / 1000, 3);
    }

    public function method(): string
    {
        return 'haversine';
    }
}
