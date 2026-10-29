<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

/**
 * Phase 3C (2026-10-25). FatherAuthController::verifyEmailChangeOtp()
 * previously type-hinted the plain Illuminate\Http\Request — zero
 * validation on `otp` or the new `email` destination. The new email is
 * optional here (verifying the OTP alone is valid — see that method's
 * doc comment), but when given it must actually look like an email.
 */
class VerifyEmailChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'otp' => ['required', 'digits:6'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => __('validation.custom.otp.required'),
            'otp.digits' => __('validation.custom.otp.digits'),
            'email.email' => __('validation.custom.email.email'),
        ];
    }
}
