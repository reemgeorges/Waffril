<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;

class MinistryLoginRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => __('validation.custom.email.required'),
            'email.email'       => __('validation.custom.email.email'),
            'password.required' => __('validation.custom.password.required'),
        ];
    }
}
