<?php

namespace App\Contracts\Delivery;

/**
 * WAFFRLI P0 (2026-10-30) — replaceable delivery-distance strategy.
 *
 * Straight-line (Haversine) is the PROVISIONAL implementation, not an
 * approved business rule. A road-distance provider can replace it by
 * adding another implementation and pointing config('delivery.
 * distance_strategy') at it; every quote records method() so historical
 * orders (P5) show which strategy produced their distance.
 */
interface DistanceCalculator
{
    /** Distance in kilometres between two WGS84 points. */
    public function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float;

    /** Short identifier stored alongside every computed distance, e.g. "haversine". */
    public function method(): string;
}
