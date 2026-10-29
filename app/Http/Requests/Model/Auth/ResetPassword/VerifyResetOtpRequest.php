<?php

namespace App\Http\Requests\Model\Auth\ResetPassword;

use App\Http\Requests\Basic\BasicRequest;

class VerifyResetOtpRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'actor'        => ['required','in:user,admin'],

            'email'        => ['nullable','email','required_without_all:prefix_phone,phone_number'],
            'prefix_phone' => ['nullable','string','max:10','required_with:phone_number','required_without:email'],
            'phone_number' => ['nullable','string','max:30','required_with:prefix_phone','required_without:email'],

            'otp'          => ['required','digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'actor.required'   => __('validation.required', ['attribute' => 'actor']),
            'actor.in'         => __('validation.in', ['attribute' => 'actor']),

            'email.required_without_all' => __('validation.custom.email.required'),
            'email.email'      => __('validation.custom.email.email'),

            'prefix_phone.required_with' => __('validation.custom.prefix_phone.required'),
            'phone_number.required_with' => __('validation.custom.phone.required'),

            'otp.required' => __('validation.custom.otp.required'),
            'otp.digits'   => __('validation.custom.otp.digits'),
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
