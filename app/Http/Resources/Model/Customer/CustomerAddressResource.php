<?php

namespace App\Http\Resources\Model\Customer;

use App\Http\Resources\Basic\BasicResource;
use Illuminate\Http\Request;

/** WAFFRLI customer API (2026-11-03). */
class CustomerAddressResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => $a->id,
            'label' => $a->label,
            'address_line' => $a->address_line,
            'details' => $a->details,
            'lat' => (float) $a->lat,
            'lng' => (float) $a->lng,
            'city' => $a->city ? ['id' => $a->city->id, 'name' => $a->city->name] : null,
            'is_default' => (bool) $a->is_default,
        ];
    }
}
