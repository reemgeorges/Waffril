<?php

namespace App\Http\Requests\Model\Push;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI (2026-11-03) — register (POST) or update (PATCH) an FCM device token. */
class DeviceTokenRequest extends BasicRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'token' => [$creating ? 'required' : 'sometimes', 'string', 'min:20', 'max:255'],
            'platform' => [$creating ? 'required' : 'sometimes', 'string', 'in:android,ios,web'],
            'device_id' => [$creating ? 'required' : 'prohibited', 'string', 'max:191'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
