<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). */
class CustomerLatLngRequest extends BasicRequest
{
    public function rules(): array
    {
        return ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']];
    }
}
