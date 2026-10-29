<?php

namespace App\Http\Requests\Model\Auth\ResetPassword;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendResetOtpRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'actor' => ['required', Rule::in(['user', 'admin'])],

            // Admin: email required. User: email أو phone
            'email' => ['nullable', 'email', 'required_if:actor,admin', 'required_without_all:prefix_phone,phone_number'],
            'prefix_phone' => ['nullable', 'string', 'max:10', 'required_with:phone_number', 'prohibited_if:actor,admin'],
            'phone_number' => ['nullable', 'string', 'max:30', 'required_with:prefix_phone', 'prohibited_if:actor,admin'],

            // القناة: admin ⇒ email فقط، user ⇒ sms/whatsapp/email.
            // Phase 3B: reads config/otp.php's configured channels instead
            // of a hardcoded list (same reasoning as RegisterRequest).
            // Phase 11D (2026-10-26): "fake" never actually delivers
            // anywhere — excluded from this allowlist in production.
            // OtpService::resolveChannel() is the authoritative
            // enforcement; this is a defense-in-depth, clearer-error
            // layer in front of it.
            'channel' => ['required', Rule::in($this->allowedOtpChannels())],
        ];
    }

    protected function allowedOtpChannels(): array
    {
        $channels = array_keys(config('otp.channels', []));

        if (app()->environment('production')) {
            $channels = array_values(array_filter($channels, fn ($c) => $c !== 'fake'));
        }

        return $channels;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('actor') === 'admin' && $this->input('channel') !== 'email') {
                $v->errors()->add('channel', __('validation.custom.channel.admin_email_only'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'actor.required' => __('validation.required', ['attribute' => 'actor']),
            'actor.in' => __('validation.in', ['attribute' => 'actor']),

            'email.required' => __('validation.custom.email.required'),
            'email.email' => __('validation.custom.email.email'),

            'prefix_phone.required_with' => __('validation.custom.prefix_phone.required'),
            'phone_number.required_with' => __('validation.custom.phone.required'),

            'channel.required' => __('validation.custom.channel.required'),
            'channel.in' => __('validation.custom.channel.in'),
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
