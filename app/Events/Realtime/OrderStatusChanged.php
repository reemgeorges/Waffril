<?php

namespace App\Events\Realtime;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * WAFFRLI (2026-11-03) — an order changed status (or driver). Published by
 * OrderStateMachine for EVERY transition, to:
 *   order.{id}               the customer's live order screen
 *   user.{customer}          the customer's order lists
 *   driver.{current|prev}    the delivering driver; a previous driver learns
 *                            that the delivery was taken away
 *   admin.orders             operations dashboards
 * The payload carries identifiers and the new state only — no addresses,
 * phone numbers, amounts or positions; clients re-fetch details over the
 * authenticated API.
 */
class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public int $userId,
        public string $from,
        public string $to,
        public ?int $driverId,
        public ?int $previousDriverId,
        public string $at,
    ) {}

    public static function fromTransition(Order $order, string $from, ?int $previousDriverId): self
    {
        return new self(
            (int) $order->id, (string) $order->order_number, (int) $order->user_id, $from, $order->status->value,
            $order->driver_id !== null ? (int) $order->driver_id : null, $previousDriverId, now()->toIso8601String(),
        );
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('order.'.$this->orderId),
            new PrivateChannel('user.'.$this->userId),
            new PrivateChannel('admin.orders'),
        ];
        foreach (array_unique(array_filter([$this->driverId, $this->previousDriverId])) as $driverId) {
            $channels[] = new PrivateChannel('driver.'.$driverId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'from' => $this->from,
            'status' => $this->to,
            // Display text in both languages (one channel can have AR and EN listeners);
            // `status` stays the stable machine value.
            'status_labels' => ['ar' => __('admin.order_status.'.$this->to, [], 'ar'), 'en' => __('admin.order_status.'.$this->to, [], 'en')],
            'driver_changed' => $this->driverId !== $this->previousDriverId,
            'tracking' => in_array($this->to, ['assigned', 'picked_up'], true) && $this->driverId !== null,
            'at' => $this->at,
        ];
    }
}
