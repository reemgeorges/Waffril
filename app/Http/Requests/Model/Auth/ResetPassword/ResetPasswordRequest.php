<?php

namespace App\Http\Requests\Model\Auth\ResetPassword;

use App\Http\Requests\Basic\BasicRequest;

/**
 * Phase 3B (2026-10-25): added `reset_token`, `actor`, `email`/
 * `prefix_phone`+`phone_number` — needed so
 * App\Services\Basic\BaseAuthService::resetPassword() can both identify
 * the account and consume the single-use grant verifyResetOtp() issued
 * (see that method's doc comment for the vulnerability this closes).
 */
class ResetPasswordRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'actor' => ['required', 'in:user,admin'],
            'email' => ['nullable', 'email', 'required_without_all:prefix_phone,phone_number'],
            'prefix_phone' => ['nullable', 'string', 'max:10', 'required_with:phone_number'],
            'phone_number' => ['nullable', 'string', 'max:30', 'required_with:prefix_phone'],
            'reset_token' => ['required', 'string'],
            'password' => ['required','string','min:6','confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.required' => __('validation.custom.reset_token.required'),
            'password.required'  => __('validation.custom.password.required'),
            'password.confirmed' => __('validation.custom.password.confirmed'),
            'password.min'       => __('validation.custom.password.min'),
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
