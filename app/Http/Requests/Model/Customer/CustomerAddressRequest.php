<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). The city is derived from (and checked against) the coordinates. */
class CustomerAddressRequest extends BasicRequest
{
    public function rules(): array
    {
        $create = $this->isMethod('post');

        return [
            'label' => ['nullable', 'string', 'max:60'],
            'address_line' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:500'],
            'lat' => [$create ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'lng' => [$create ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
