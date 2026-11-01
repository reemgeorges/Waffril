<?php

namespace App\Http\Requests\Model\Customer;

/** WAFFRLI customer API (2026-11-03). */
class CustomerVerifyOtpRequest extends CustomerPhoneRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
