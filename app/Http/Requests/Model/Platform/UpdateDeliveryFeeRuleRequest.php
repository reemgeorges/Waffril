<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). The band (from < to) and scope are
 * re-validated against the merged record in DeliveryFeeRuleService.
 */
class UpdateDeliveryFeeRuleRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:delivery_fee_rules,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'delivery_area_id' => ['sometimes', 'nullable', 'integer', 'exists:delivery_areas,id'],
            'distance_from_km' => ['sometimes', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'distance_to_km' => ['sometimes', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'base_fee' => ['sometimes', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'per_km_fee' => ['sometimes', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'is_active' => ['prohibited'],
        ];
    }
}
