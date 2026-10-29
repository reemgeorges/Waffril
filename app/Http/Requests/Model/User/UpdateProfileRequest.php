<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class UpdateProfileRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'name'          => 'nullable|string|max:255',
            'profile_image' => 'nullable|string|max:255',
            'city_id'       => 'nullable|integer|exists:cities,id',
            'country_id'    => 'nullable|integer|exists:countries,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => __('messages.name_string'),
            'name.max'    => __('messages.name_max'),

            'profile_image.string' => __('messages.profile_image_string'),
            'profile_image.max'    => __('messages.profile_image_max'),

            'city_id.integer' => __('messages.city_id_integer'),
            'city_id.exists'  => __('messages.city_id_exists'),

            'country_id.integer' => __('messages.country_id_integer'),
            'country_id.exists'  => __('messages.country_id_exists'),
        ];
    }
}
