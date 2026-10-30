<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * WAFFRLI (2026-11-03) — something operations staff must react to (new
 * order, cancellation request, driver problem report, PLUS request).
 * Broadcast on `admin.orders` (admins with orders.view) with identifiers
 * only; the panel re-reads the record with the viewer's own permissions.
 */
class AdminOperationalEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const TYPES = ['order.placed', 'order.cancellation_requested', 'order.problem_reported', 'plus.request_submitted'];

    public function __construct(
        public string $type,
        public int $subjectId,
        public ?string $reference = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'admin.operational';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'id' => $this->subjectId, 'reference' => $this->reference, 'at' => now()->toIso8601String()];
    }
}
