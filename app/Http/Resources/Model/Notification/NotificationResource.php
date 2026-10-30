<?php

namespace App\Http\Resources\Model\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WAFFRLI (2026-11-03) — a stored notification in the reader's language:
 * event, title, message and the identifiers it references.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $n = $this->resource;
        $data = (array) $n->data;
        $text = fn ($v) => is_array($v) ? ($v[app()->getLocale()] ?? reset($v)) : $v;

        return [
            'id' => $n->id,
            'type' => $n->type,
            'event' => $data['event'] ?? null,
            'title' => $text($data['title'] ?? null),
            'message' => $text($data['message'] ?? null),
            'references' => array_diff_key($data, array_flip(['event', 'title', 'message', 'format'])),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
