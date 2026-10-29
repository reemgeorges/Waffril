<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class UpdateLanguageRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'language' => 'required|string|max:8',
        ];
    }

    public function messages(): array
    {
        return [
            'language.required' => __('messages.language_required'),
            'language.string'   => __('messages.language_string'),
            'language.max'      => __('messages.language_max'),
        ];
    }
}
