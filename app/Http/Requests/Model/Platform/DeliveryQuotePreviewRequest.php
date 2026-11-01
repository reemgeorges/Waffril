<?php

namespace App\Http\Requests\Model\Platform;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI P0 (2026-10-30). Admin-only preview of the platform fee rules
 * for an origin → destination pair inside a city (no order is created).
 */
class DeliveryQuotePreviewRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
