<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LoginRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            // حالة 1: email + password
            'email'        => ['nullable','email'],
            'password'     => ['nullable','string'],

            // حالة 2: phone + password
            'prefix_phone' => ['nullable','string','max:10'],
            'phone_number' => ['nullable','string','max:30'],

            // حالة 3: social login (social_id فقط)
            'social_id'    => ['nullable','string','max:255'],

            // توكن الجهاز (اختياري)
            'token_device' => ['nullable','string'],
            'platform'     => ['nullable', Rule::in(['android', 'ios', 'web', 'unknown'])],
            'device_name'  => ['nullable','string','max:255'],
            'device_id'    => ['nullable','string','max:255'],

        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $email  = (string) $this->input('email', '');
            $pass   = (string) $this->input('password', '');
            $pp     = (string) $this->input('prefix_phone', '');
            $pn     = (string) $this->input('phone_number', '');
            $social = (string) $this->input('social_id', '');

            $emailCase  = $email !== '' && $pass !== '';
            $phoneCase  = $pp !== '' && $pn !== '' && $pass !== '';
            $socialCase = $social !== '' && $pass === '' && $email === '' && $pp === '' && $pn === '';

            if (!($emailCase || $phoneCase || $socialCase)) {
                $v->errors()->add('login', __('validation.custom.login.invalid_combination'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'email.email' => __('validation.custom.email.email'),
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
