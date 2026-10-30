<?php

namespace App\Http\Resources\Model\Driver;

use App\Http\Resources\Basic\BasicResource;
use Illuminate\Http\Request;

/** WAFFRLI driver API (2026-11-02) — a stored notification of the authenticated driver. */
class DriverNotificationResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $n = $this->resource;
        $data = (array) $n->data;
        $message = $data['message'] ?? null;

        return [
            'id' => $n->id,
            'type' => $n->type,
            'event' => $data['event'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'order_number' => $data['order_number'] ?? null,
            'message' => is_array($message) ? ($message[app()->getLocale()] ?? reset($message)) : $message,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
