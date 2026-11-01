<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). First and last name are required to complete the account. */
class CustomerRegisterRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string', 'max:2000'],
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
            'language' => ['nullable', 'in:ar,en'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
