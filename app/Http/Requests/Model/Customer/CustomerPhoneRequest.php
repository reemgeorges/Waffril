<?php

namespace App\Http\Requests\Model\Customer;

use App\Http\Requests\Basic\BasicRequest;
use App\Support\Phone\PhoneNumber;

/**
 * WAFFRLI customer API (2026-11-03). Normalizes `phone` to E.164 before
 * validation; base for every phone-based customer request.
 */
class CustomerPhoneRequest extends BasicRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize((string) $this->input('phone')) ?? 'invalid']);
        }
    }

    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/']];
    }

    public function messages(): array
    {
        return ['phone.regex' => __('messages.customer.invalid_phone')];
    }
}
