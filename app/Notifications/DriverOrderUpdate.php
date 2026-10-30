<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * WAFFRLI driver API (2026-11-02) — tells a driver that one of their
 * deliveries changed (assigned, unassigned, cancelled). Stored in the
 * `notifications` table only; via() is the single place to add the FCM
 * channel when push delivery is authorised.
 */
class DriverOrderUpdate extends Notification
{
    public const EVENTS = ['assigned', 'unassigned', 'cancelled'];

    public function __construct(public string $event, public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'driver.order_'.$this->event;
    }

    public function toArray(object $notifiable): array
    {
        $messages = [
            'assigned' => ['en' => 'New delivery assigned: :number', 'ar' => 'تم إسناد توصيل جديد: :number'],
            'unassigned' => ['en' => 'Delivery :number was removed from you', 'ar' => 'تم سحب التوصيل :number منك'],
            'cancelled' => ['en' => 'Delivery :number was cancelled', 'ar' => 'تم إلغاء التوصيل :number'],
        ][$this->event];

        return [
            'event' => $this->event,
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'message' => array_map(fn ($text) => str_replace(':number', $this->order->order_number, $text), $messages),
        ];
    }
}
