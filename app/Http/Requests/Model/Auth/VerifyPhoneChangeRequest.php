<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

/**
 * Phase 3C (2026-10-25). FatherAuthController::verifyPhoneChangeOtp()
 * previously type-hinted the plain Illuminate\Http\Request — zero
 * validation on `otp`, `prefix_phone`, or `phone_number`, meaning any
 * malformed destination could reach
 * BaseAuthService::verifyPhoneChangeOtp()'s uniqueness query. The new
 * phone number is optional here (verifying the OTP alone, without
 * changing the number, is a valid call — see that method), but when
 * given, both parts are required together and format-checked.
 */
class VerifyPhoneChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'otp' => ['required', 'digits:6'],

            // E.164-ish: a short digit-only country/dialing prefix
            // (optionally '+'-led) and a plain digit-only subscriber
            // number — rejects whitespace, letters, and most injection-ish
            // garbage without being locale-specific.
            'prefix_phone' => ['nullable', 'regex:/^\+?[0-9]{1,4}$/', 'required_with:phone_number'],
            'phone_number' => ['nullable', 'regex:/^[0-9]{4,15}$/', 'required_with:prefix_phone'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => __('validation.custom.otp.required'),
            'otp.digits' => __('validation.custom.otp.digits'),
            'prefix_phone.regex' => __('validation.custom.prefix_phone.format'),
            'phone_number.regex' => __('validation.custom.phone.format'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone_number')) {
            $this->merge([
                'phone_number' => preg_replace('/[\s-]+/', '', (string) $this->input('phone_number')),
            ]);
        }
    }
}
