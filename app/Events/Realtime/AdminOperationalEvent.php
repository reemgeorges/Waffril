<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * WAFFRLI (2026-11-03) — something operations staff must react to (new
 * order, cancellation request, driver problem report, PLUS request), and
 * (dispatch B4, 2026-11-10) `order.dispatch_updated`: a delivery offer was
 * sent, answered, expired or withdrawn, or the order entered the no-driver
 * queue. It only refreshes open admin screens (no bell alert).
 * Broadcast on `admin.orders` (admins with orders.view) with identifiers
 * only; the panel re-reads the record with the viewer's own permissions.
 *
 * 2026-11-16: changes that are not about one order (PLUS decisions,
 * customer and driver status, settlements, marketing progress) go to
 * `admin.activity` instead, which every active admin may listen to: the
 * screens that show them re-read the database with the viewer's own
 * permissions, so a PLUS manager without orders.view still sees live PLUS
 * updates. Payloads stay identifiers only.
 */
class AdminOperationalEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const TYPES = [
        'order.placed', 'order.cancellation_requested', 'order.problem_reported', 'order.dispatch_updated', 'order.refunded',
        'plus.request_submitted', 'plus.request_resolved', 'plus.membership_changed',
        'customer.status_changed', 'driver.status_changed', 'settlement.updated', 'marketing.progress',
    ];

    public function __construct(
        public string $type,
        public int $subjectId,
        public ?string $reference = null,
    ) {}

    public function broadcastOn(): array
    {
        // plus.request_submitted stays on admin.orders too (compatibility with open order screens).
        $channels = str_starts_with($this->type, 'order.') || $this->type === 'plus.request_submitted' ? [new PrivateChannel('admin.orders')] : [];
        if (! str_starts_with($this->type, 'order.')) {
            $channels[] = new PrivateChannel('admin.activity');
        }

        return $channels;
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
