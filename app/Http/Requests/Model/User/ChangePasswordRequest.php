<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class ChangePasswordRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'old_password'         => 'required|string|min:6',
            'new_password'         => 'required|string|min:6|different:old_password',
            'new_password_confirm' => 'required|string|same:new_password',
        ];
    }

    public function messages(): array
    {
        return [
            'old_password.required' => __('messages.old_password_required'),
            'old_password.string'   => __('messages.old_password_string'),
            'old_password.min'      => __('messages.old_password_min'),

            'new_password.required'  => __('messages.new_password_required'),
            'new_password.string'    => __('messages.new_password_string'),
            'new_password.min'       => __('messages.new_password_min'),
            'new_password.different' => __('messages.new_password_different'),

            'new_password_confirm.required' => __('messages.new_password_confirm_required'),
            'new_password_confirm.string'   => __('messages.new_password_confirm_string'),
            'new_password_confirm.same'     => __('messages.new_password_confirm_same'),
        ];
    }
}
