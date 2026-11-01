<?php

namespace App\Services\Functional\Delivery;

use App\Models\DeliveryArea;
use App\Support\Geo\Polygon;

/**
 * WAFFRLI P0 (2026-10-30) — finds the active delivery area of a city
 * that contains a point: indexed bounding-box prefilter in SQL, exact
 * point-in-polygon in PHP. When several active areas overlap, the one
 * with the lowest sort_order (then id) wins, deterministically.
 */
class DeliveryAreaLocator
{
    public function locate(int $cityId, float $lat, float $lng): ?DeliveryArea
    {
        $candidates = DeliveryArea::query()
            ->where('city_id', $cityId)
            ->where('is_active', true)
            ->where('min_lat', '<=', $lat)
            ->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)
            ->where('max_lng', '>=', $lng)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $area) {
            if (Polygon::contains((array) $area->boundary, $lat, $lng)) {
                return $area;
            }
        }

        return null;
    }

    public function cityHasActiveAreas(int $cityId): bool
    {
        return DeliveryArea::query()
            ->where('city_id', $cityId)
            ->where('is_active', true)
            ->exists();
    }
}
