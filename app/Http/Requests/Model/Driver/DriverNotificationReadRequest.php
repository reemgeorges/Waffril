<?php

namespace App\Http\Requests\Model\Driver;

use App\Http\Requests\Basic\BasicRequest;

/** WAFFRLI driver API (2026-11-02). Without `ids`, every unread notification is marked read. */
class DriverNotificationReadRequest extends BasicRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['string', 'uuid'],
        ];
    }
}
