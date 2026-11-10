<?php

namespace App\Services\Driver;

use App\Contracts\Delivery\DistanceCalculator;
use App\Enums\OrderStatus;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WAFFRLI (2026-10-31) — automatic driver RECOMMENDATION (the admin
 * always confirms the assignment). Candidates: active, `available`
 * drivers in the order's city with no other active delivery, nearest to
 * the branch first (drivers without a known position are listed last).
 *
 * Dispatch B3 (2026-11-10): dispatchCandidates() ranks drivers for the
 * automatic next offer of a delivery-offer round (fair rotation).
 */
class DriverRecommendationService
{
    public function __construct(protected DistanceCalculator $distance) {}

    /** @return Collection<int, array{driver: Driver, distance_km: ?float}> */
    public function recommend(Order $order, ?int $limit = null): Collection
    {
        $limit ??= (int) PlatformSettings::get('delivery.driver_recommendation_limit');
        $branch = $order->branch;

        return $this->eligibleQuery($order)
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

    /**
     * Dispatch B3: drivers for the next automatic offer of $order in $round,
     * fairest first. On top of the eligibility above, excludes drivers who
     * are answering another offer and drivers already offered this order
     * in this round (rejected, expired or withdrawn).
     *
     * Ranking (fair rotation):
     *  1. longest wait since the driver's last offer (never offered first);
     *  2. fewest deliveries completed today (operations time zone);
     *  3. nearest to the branch, but ONLY with a position reported within
     *     the `dispatch.location_fresh_minutes` setting; stale or missing
     *     positions are ignored (ranked after fresh ones);
     *  4. driver id, for a stable order.
     *
     * One query for the pool (last offer and today's deliveries are
     * subqueries), then ranking in PHP: no per-driver queries.
     *
     * @return Collection<int, array{driver: Driver, meta: array<string, mixed>}>
     */
    public function dispatchCandidates(Order $order, int $round, int $limit = 10): Collection
    {
        $branch = $order->branch;
        $freshSince = now()->subMinutes(max(1, (int) PlatformSettings::get('dispatch.location_fresh_minutes')));
        $todayStart = now(PlatformSettings::get('operations.timezone') ?: 'UTC')->startOfDay()->utc();

        return $this->eligibleQuery($order)
            ->whereNotIn('id', DeliveryOffer::query()->select('driver_id')->whereNotNull('pending_driver_marker'))
            ->whereNotIn('id', DeliveryOffer::query()->select('driver_id')->where('order_id', $order->id)->where('round', $round))
            ->addSelect(['last_offered_at' => DeliveryOffer::query()->selectRaw('MAX(offered_at)')->whereColumn('driver_id', 'drivers.id')])
            ->withCount(['orders as deliveries_today' => fn (Builder $q) => $q
                ->where('status', OrderStatus::Delivered->value)->where('delivered_at', '>=', $todayStart)])
            ->get()
            ->map(function (Driver $driver) use ($branch, $freshSince) {
                $fresh = $branch && $driver->last_lat !== null && $driver->last_location_at !== null
                    && Carbon::parse($driver->last_location_at)->greaterThanOrEqualTo($freshSince);
                $distance = $fresh
                    ? round($this->distance->distanceKm((float) $branch->lat, (float) $branch->lng, (float) $driver->last_lat, (float) $driver->last_lng), 3)
                    : null;
                $lastOffered = $driver->last_offered_at ? Carbon::parse($driver->last_offered_at) : null;

                return [
                    'driver' => $driver,
                    'meta' => [
                        'strategy' => 'fair_rotation',
                        'last_offered_at' => $lastOffered?->toIso8601String(),
                        'deliveries_today' => (int) $driver->deliveries_today,
                        'distance_km' => $distance,
                        'location_used' => $fresh,
                    ],
                    'sort' => [$lastOffered?->getTimestamp() ?? 0, (int) $driver->deliveries_today, $distance ?? PHP_FLOAT_MAX, (int) $driver->id],
                ];
            })
            ->sort(fn ($a, $b) => $a['sort'] <=> $b['sort'])
            ->take(max(1, $limit))
            ->values()
            ->map(fn ($row, $index) => ['driver' => $row['driver'], 'meta' => $row['meta'] + ['rank' => $index + 1]]);
    }

    /** Active, available drivers of the order's city without another active delivery. */
    protected function eligibleQuery(Order $order): Builder
    {
        return Driver::query()
            ->select('drivers.*')
            ->where('city_id', $order->city_id)
            ->where('is_active', true)
            ->where('availability', Driver::AVAILABILITY_AVAILABLE)
            ->whereDoesntHave('orders', fn ($q) => $q
                ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])
                ->whereKeyNot($order->id));
    }
}
