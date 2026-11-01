<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * WAFFRLI (2026-11-03) — order events for the customer (stored, live on
 * user.{id}, FCM). Identifiers only; the app re-reads the order.
 */
class CustomerOrderUpdate extends AppNotification
{
    /** Order events a customer is told about (texts: lang/{ar,en}/notifications.php). */
    public const KINDS = ['accepted', 'assigned', 'picked_up', 'delivered', 'cancelled', 'cancellation_rejected', 'refunded'];

    public function __construct(public string $kind, public Order $order) {}

    public function event(): string
    {
        return 'order.'.$this->kind;
    }

    protected function texts(): array
    {
        $number = ['number' => $this->order->order_number];

        return [
            'title' => $this->both('notifications.customer.order_title', $number),
            'body' => $this->both('notifications.customer.order.'.$this->kind, $number),
        ];
    }

    protected function references(): array
    {
        return ['order_id' => $this->order->id, 'order_number' => $this->order->order_number];
    }
}
