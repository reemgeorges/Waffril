<?php

namespace App\Services\Functional\Delivery;

/**
 * WAFFRLI P0 (2026-10-30) — result of DeliveryFeeResolver::quote().
 * `fee` is a decimal string at storage scale (4) in the DEFAULT currency;
 * rounding to a payment currency happens at checkout (P5).
 */
final class DeliveryQuote
{
    public const REASON_OUTSIDE_SERVICE_AREAS = 'outside_service_areas';

    public const REASON_NO_MATCHING_RULE = 'no_matching_fee_rule';

    public function __construct(
        public readonly bool $covered,
        public readonly ?string $reason,
        public readonly float $distanceKm,
        public readonly string $distanceMethod,
        public readonly ?int $deliveryAreaId,
        public readonly ?int $deliveryFeeRuleId,
        public readonly ?string $ruleScope,
        public readonly ?string $fee,
    ) {}

    public function toArray(): array
    {
        return [
            'covered' => $this->covered,
            'reason' => $this->reason,
            'distance_km' => $this->distanceKm,
            'distance_method' => $this->distanceMethod,
            'delivery_area_id' => $this->deliveryAreaId,
            'delivery_fee_rule_id' => $this->deliveryFeeRuleId,
            'rule_scope' => $this->ruleScope,
            'fee' => $this->fee,
        ];
    }
}
