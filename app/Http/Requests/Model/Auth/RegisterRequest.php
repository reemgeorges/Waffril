<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 3B (2026-10-25) fix: this request previously validated
 * first_name/last_name/father_name/gender/country/city/birth_date/phone —
 * none of which correspond to any column on `users` (checked the schema
 * and App\Models\User::$fillable). Worse, it never collected `name` at
 * all, even though `users.name` is a required, NOT NULL column with no
 * default — so BaseAuthService::register()'s User::create($in) call was
 * guaranteed to fatal with a database constraint violation on every
 * single call, regardless of OTP working or not. Rewritten to match the
 * actual schema (see database/migrations/0001_01_01_000000_create_users_table.php
 * and .../2026_10_25_130200_add_auth_columns_to_users_table.php).
 */
class RegisterRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without_all:prefix_phone,phone_number'],
            'prefix_phone' => ['nullable', 'string', 'max:10', 'required_with:phone_number'],
            'phone_number' => ['nullable', 'string', 'max:30', 'required_with:prefix_phone'],
            'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],

            // Channel used to deliver the registration OTP. Reads the
            // configured channel names directly (config/otp.php) instead
            // of a hardcoded list, so it can never drift out of sync with
            // what App\Services\Auth\OtpService actually supports.
            //
            // Phase 11D (2026-10-26): "fake" is excluded from this
            // allowlist in production specifically — it never actually
            // delivers anywhere (see OtpService::resolveChannel(), the
            // authoritative enforcement this is a defense-in-depth,
            // clearer-error-message layer in front of). Still allowed
            // outside production so local/staging/testing keep working
            // with no provider configured at all.
            'otp_delivery_method' => ['required', Rule::in($this->allowedOtpChannels())],

            'token_device' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('validation.custom.name.required'),

            'email.required_without_all' => __('validation.custom.email.required'),
            'email.email' => __('validation.custom.email.email'),

            'prefix_phone.required_with' => __('validation.custom.prefix_phone.required'),
            'phone_number.required_with' => __('validation.custom.phone.required'),

            'password.required' => __('validation.custom.password.required'),
            'password.confirmed' => __('validation.custom.password.confirmed'),
            'password.min' => __('validation.custom.password.min'),
            'password.max' => __('validation.custom.password.max'),

            'otp_delivery_method.required' => __('validation.custom.otp_delivery_method.required'),
            'otp_delivery_method.in' => __('validation.custom.otp_delivery_method.in'),
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

    protected function allowedOtpChannels(): array
    {
        $channels = array_keys(config('otp.channels', []));

        if (app()->environment('production')) {
            $channels = array_values(array_filter($channels, fn ($c) => $c !== 'fake'));
        }

        return $channels;
    }
}
