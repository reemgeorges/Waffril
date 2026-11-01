<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). Scope: area (city_id + delivery_area_id),
 * city (city_id only) or global (neither). Fees are in the default
 * currency, at most 4 decimals.
 */
class StoreDeliveryFeeRuleRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'city_id' => ['nullable', 'integer', 'exists:cities,id', 'required_with:delivery_area_id'],
            'delivery_area_id' => ['nullable', 'integer', 'exists:delivery_areas,id'],
            'distance_from_km' => ['required', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'distance_to_km' => ['required', 'numeric', 'gt:distance_from_km', 'max:9999.99', 'decimal:0,2'],
            'base_fee' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'per_km_fee' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
