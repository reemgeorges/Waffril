<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI customer API (2026-11-03). */
class CustomerCodeRequest extends BasicRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/^\d{4,8}$/']];
    }
}
