<?php

namespace App\Http\Requests\Model\Platform;

/**
 * WAFFRLI P0 (2026-10-30).
 */
class UpdateDeliveryAreaRequest extends StoreDeliveryAreaRequest
{
    public function rules(): array
    {
        return array_merge([
            'id' => ['required', 'integer', 'exists:delivery_areas,id'],
            'city_id' => ['sometimes', 'integer', 'exists:cities,id'],
            'boundary' => ['sometimes', 'array', $this->boundaryRule()],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['prohibited'],
            'min_lat' => ['prohibited'],
            'max_lat' => ['prohibited'],
            'min_lng' => ['prohibited'],
            'max_lng' => ['prohibited'],
        ], $this->nameRules(false));
    }
}
