<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * WAFFRLI driver API (2026-11-02) — tells a driver that one of their
 * deliveries changed (assigned, unassigned, cancelled).
 * 2026-11-03: stored + live on driver.{id} + FCM push (AppNotification).
 * The stored shape is unchanged (event = assigned|unassigned|cancelled,
 * type driver.order_{event}).
 */
class DriverOrderUpdate extends AppNotification
{
    public const EVENTS = ['assigned', 'unassigned', 'cancelled'];

    public function __construct(public string $event, public Order $order) {}

    public function event(): string
    {
        return 'driver.order_'.$this->event;
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event] + parent::toArray($notifiable);
    }

    protected function texts(): array
    {
        return [
            'title' => $this->both('notifications.driver.order_title'),
            'body' => $this->both('notifications.driver.order.'.$this->event, ['number' => $this->order->order_number]),
        ];
    }

    protected function references(): array
    {
        return ['order_id' => $this->order->id, 'order_number' => $this->order->order_number];
    }
}
