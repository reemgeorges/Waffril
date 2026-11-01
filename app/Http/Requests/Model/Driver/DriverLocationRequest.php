<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI driver API (2026-11-02). WGS84 coordinates; the (0, 0) "null
 * island" placeholder that broken GPS fixes produce is rejected.
 */
class DriverLocationRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180', function ($attribute, $value, $fail) {
                if ((float) $value === 0.0 && (float) $this->input('lat') === 0.0) {
                    $fail(__('validation.not_in', ['attribute' => 'lat/lng']));
                }
            }],
        ];
    }
}
