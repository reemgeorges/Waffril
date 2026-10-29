<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

class SocialRegisterRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'social_id'    => ['required','string','max:255'],
            // بيانات إضافية اختياري:
            'email'        => ['nullable','email','max:255'],
            'name'         => ['nullable','string','max:255'],
            'language'     => ['nullable','string','max:8'],
            'token_device' => ['nullable','string'],
        ];
    }

    public function messages(): array
    {
        return [
            'social_id.required' => __('validation.required', ['attribute' => 'social_id']),
            'email.email'        => __('validation.custom.email.email'),
        ];
    }
}
