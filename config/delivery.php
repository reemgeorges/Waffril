<?php

use App\Services\Functional\Delivery\HaversineDistanceCalculator;

/**
 * WAFFRLI P0 (2026-10-30) — delivery configuration.
 *
 * distance_strategy: which App\Contracts\Delivery\DistanceCalculator
 * implementation is bound. "haversine" (straight-line) is a PROVISIONAL
 * technical assumption, not an approved business rule; add a road-distance
 * implementation to `distance_strategies` and switch this value to replace
 * it without any schema change.
 */
return [
    'distance_strategy' => env('DELIVERY_DISTANCE_STRATEGY', 'haversine'),

    'distance_strategies' => [
        'haversine' => HaversineDistanceCalculator::class,
    ],
];
