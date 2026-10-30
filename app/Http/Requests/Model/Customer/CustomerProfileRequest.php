<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). Phone is never editable here (it is the login identity). */
class CustomerProfileRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'language' => ['sometimes', 'in:ar,en'],
            'display_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:currencies,id'],
        ];
    }
}
