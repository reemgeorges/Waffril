<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class VerifyEmailChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'new_email' => 'required|email|max:255',
            'otp'       => 'required|string|min:4|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'new_email.required' => __('messages.new_email_required'),
            'new_email.email'    => __('messages.new_email_email'),
            'new_email.max'      => __('messages.new_email_max'),

            'otp.required' => __('messages.otp_required'),
            'otp.string'   => __('messages.otp_string'),
            'otp.min'      => __('messages.otp_min'),
            'otp.max'      => __('messages.otp_max'),

            'channel.in' => __('messages.channel_in'),
        ];
    }
}
