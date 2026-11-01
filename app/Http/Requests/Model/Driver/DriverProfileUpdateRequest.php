<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;

/**
 * WAFFRLI driver API (2026-11-02). Only the app language is self-service;
 * name, phone, city and vehicle are managed by admins in Filament.
 */
class DriverProfileUpdateRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'language' => ['required', 'string', 'in:ar,en'],
        ];
    }
}
