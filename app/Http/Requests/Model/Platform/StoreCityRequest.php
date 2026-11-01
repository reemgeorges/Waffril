<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). Used for create; UpdateCityRequest relaxes
 * every field to `sometimes`.
 */
class StoreCityRequest extends BasicRequest
{
    use TranslatableNameRules;

    public function rules(): array
    {
        return array_merge([
            'center_lat' => ['required', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'center_lng' => ['required', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'radius_km' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->nameRules());
    }
}
