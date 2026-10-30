<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class UpdateCityRequest extends BasicRequest
{
    use TranslatableNameRules;

    public function rules(): array
    {
        return array_merge([
            'id' => ['required', 'integer', 'exists:cities,id'],
            'center_lat' => ['sometimes', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'center_lng' => ['sometimes', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'radius_km' => ['sometimes', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['prohibited'],
        ], $this->nameRules(false));
    }
}
