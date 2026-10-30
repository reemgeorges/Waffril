<?php

namespace App\Services\Driver;

use App\Contracts\Delivery\DistanceCalculator;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Collection;

/**
 * WAFFRLI (2026-10-31) — automatic driver RECOMMENDATION (the admin
 * always confirms the assignment). Candidates: active, `available`
 * drivers in the order's city with no other active delivery, nearest to
 * the branch first (drivers without a known position are listed last).
 */
class DriverRecommendationService
{
    public function __construct(protected DistanceCalculator $distance) {}

    /** @return Collection<int, array{driver: Driver, distance_km: ?float}> */
    public function recommend(Order $order, ?int $limit = null): Collection
    {
        $limit ??= (int) PlatformSettings::get('delivery.driver_recommendation_limit');
        $branch = $order->branch;

        return Driver::query()
            ->where('city_id', $order->city_id)
            ->where('is_active', true)
            ->where('availability', Driver::AVAILABILITY_AVAILABLE)
            ->whereDoesntHave('orders', fn ($q) => $q
                ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])
                ->whereKeyNot($order->id))
            ->get()
            ->map(fn (Driver $driver) => [
                'driver' => $driver,
                'distance_km' => ($driver->last_lat !== null && $branch)
                    ? $this->distance->distanceKm((float) $branch->lat, (float) $branch->lng, (float) $driver->last_lat, (float) $driver->last_lng)
                    : null,
            ])
            ->sortBy(fn ($row) => $row['distance_km'] ?? PHP_FLOAT_MAX)
            ->take(max(1, $limit))
            ->values();
    }

    public function isEligible(Driver $driver, Order $order): bool
    {
        return $this->recommend($order, PHP_INT_MAX)->contains(fn ($row) => $row['driver']->is($driver));
    }
}
