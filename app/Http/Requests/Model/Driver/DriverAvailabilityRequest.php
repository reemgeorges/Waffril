<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;
use App\Models\Driver;

/** WAFFRLI driver API (2026-11-02). Only the two approved states exist. */
class DriverAvailabilityRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'availability' => ['required', 'string', 'in:'.Driver::AVAILABILITY_AVAILABLE.','.Driver::AVAILABILITY_OFFLINE],
        ];
    }
}
