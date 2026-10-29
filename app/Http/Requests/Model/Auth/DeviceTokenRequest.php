<?php

namespace App\Http\Requests\Model\Auth;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Validation\Rule;

class DeviceTokenRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'token_device' => ['required','string','max:512'],
            'platform'     => ['nullable', Rule::in(['android', 'ios', 'web', 'unknown'])],
            'device_name'  => ['nullable','string','max:255'],
            'device_id'    => ['nullable','string','max:255'],
        ];
    }
}
