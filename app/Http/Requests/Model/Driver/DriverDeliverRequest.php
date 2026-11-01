<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI driver API (2026-11-02). `cash_collected` is the cash actually
 * received (required when cash is due; the service checks it equals the
 * amount due). `photo` is an optional proof of delivery (multipart).
 */
class DriverDeliverRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'cash_collected' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
