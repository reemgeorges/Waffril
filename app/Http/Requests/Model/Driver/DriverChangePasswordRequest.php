<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI driver API (2026-11-02). */
class DriverChangePasswordRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed', 'different:current_password'],
        ];
    }
}
