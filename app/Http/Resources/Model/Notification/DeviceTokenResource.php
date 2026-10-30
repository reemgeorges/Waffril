<?php

namespace App\Http\Resources\Model\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** WAFFRLI (2026-11-03) — a device token, never echoed in full. */
class DeviceTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'device_id' => $this->device_id,
            'device_name' => $this->device_name,
            'token_hint' => '…'.substr((string) $this->token, -6),
            'is_active' => (bool) $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
