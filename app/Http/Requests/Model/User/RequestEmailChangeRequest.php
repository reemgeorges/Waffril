<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class RequestEmailChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'new_email' => 'required|email|max:255|unique:users,email',
            'channel'   => 'nullable|in:email,sms,whatsapp',
        ];
    }

    public function messages(): array
    {
        return [
            'new_email.required' => __('messages.new_email_required'),
            'new_email.email'    => __('messages.new_email_email'),
            'new_email.max'      => __('messages.new_email_max'),
            'new_email.unique'   => __('messages.new_email_unique'),

            'channel.in' => __('messages.channel_in'),
        ];
    }
}
