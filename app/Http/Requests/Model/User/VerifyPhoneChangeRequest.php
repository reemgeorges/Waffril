<?php

namespace App\Http\Requests\Model\User;

use App\Http\Requests\Basic\BasicRequest;

class VerifyPhoneChangeRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'prefix_phone' => 'required|string|max:10',
            'phone_number' => 'required|string|max:30',
            'otp'          => 'required|string|min:4|max:10',
            'channel'      => 'nullable|in:sms,whatsapp,email',
        ];
    }

    public function messages(): array
    {
        return [
            'prefix_phone.required' => __('messages.prefix_phone_required'),
            'prefix_phone.string'   => __('messages.prefix_phone_string'),
            'prefix_phone.max'      => __('messages.prefix_phone_max'),

            'phone_number.required' => __('messages.phone_number_required'),
            'phone_number.string'   => __('messages.phone_number_string'),
            'phone_number.max'      => __('messages.phone_number_max'),

            'otp.required' => __('messages.otp_required'),
            'otp.string'   => __('messages.otp_string'),
            'otp.min'      => __('messages.otp_min'),
            'otp.max'      => __('messages.otp_max'),

            'channel.in' => __('messages.channel_in'),
        ];
    }
}
