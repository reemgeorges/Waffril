<?php

namespace App\Services\Functional\Delivery;

use App\Contracts\Delivery\DistanceCalculator;
use App\Models\DeliveryFeeRule;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;

/**
 * WAFFRLI P0 (2026-10-30) — platform-wide delivery coverage and fee.
 *
 * 1. If the city has active delivery areas, the destination must be
 *    inside one of them; otherwise the whole city is eligible.
 * 2. Distance comes from the bound DistanceCalculator (replaceable).
 * 3. Scope precedence: area → city → global. The MOST SPECIFIC scope
 *    that has any active rule is authoritative: if that scope's bands do
 *    not cover the distance, the destination is NOT covered (a broader
 *    scope never silently extends a narrower scope's limits).
 * 4. Band match: distance_from_km <= d < distance_to_km.
 * 5. Fee = base_fee + per_km_fee * (d - distance_from_km), default
 *    currency, storage scale.
 *
 * The city-match check between the branch and the delivery address is a
 * checkout concern (P5) — this resolver receives the city it is told.
 */
class DeliveryFeeResolver
{
    public function __construct(
        protected DistanceCalculator $distance,
        protected DeliveryAreaLocator $areas,
    ) {}

    public function quote(int $cityId, float $originLat, float $originLng, float $destLat, float $destLng): DeliveryQuote
    {
        $distanceKm = $this->distance->distanceKm($originLat, $originLng, $destLat, $destLng);
        $method = $this->distance->method();

        $area = $this->areas->locate($cityId, $destLat, $destLng);
        if ($area === null && $this->areas->cityHasActiveAreas($cityId)) {
            return new DeliveryQuote(false, DeliveryQuote::REASON_OUTSIDE_SERVICE_AREAS, $distanceKm, $method, null, null, null, null);
        }

        $scopeKeys = array_values(array_filter([
            $area ? DeliveryFeeRule::scopeKeyFor($cityId, $area->id) : null,
            DeliveryFeeRule::scopeKeyFor($cityId, null),
            DeliveryFeeRule::scopeKeyFor(null, null),
        ]));

        foreach ($scopeKeys as $scopeKey) {
            $rules = DeliveryFeeRule::query()
                ->where('scope_key', $scopeKey)
                ->where('is_active', true)
                ->orderBy('distance_from_km')
                ->get();

            if ($rules->isEmpty()) {
                continue;
            }

            $d = BigDecimal::of((string) $distanceKm);
            foreach ($rules as $rule) {
                $from = BigDecimal::of((string) $rule->distance_from_km);
                $to = BigDecimal::of((string) $rule->distance_to_km);

                if ($d->isGreaterThanOrEqualTo($from) && $d->isLessThan($to)) {
                    $fee = BigDecimal::of((string) $rule->base_fee)
                        ->plus(BigDecimal::of((string) $rule->per_km_fee)->multipliedBy($d->minus($from)));

                    return new DeliveryQuote(
                        true,
                        null,
                        $distanceKm,
                        $method,
                        $area?->id,
                        $rule->id,
                        $rule->scopeType(),
                        Money::toStorage($fee),
                    );
                }
            }

            return new DeliveryQuote(false, DeliveryQuote::REASON_NO_MATCHING_RULE, $distanceKm, $method, $area?->id, null, null, null);
        }

        return new DeliveryQuote(false, DeliveryQuote::REASON_NO_MATCHING_RULE, $distanceKm, $method, $area?->id, null, null, null);
    }
}
