<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;
use App\Notifications\DriverDeliveryProblemReported;

/** WAFFRLI driver API (2026-11-02). */
class DriverProblemRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', DriverDeliveryProblemReported::TYPES)],
            'note' => ['nullable', 'required_if:type,other', 'string', 'max:500'],
        ];
    }
}
