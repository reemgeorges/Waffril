<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * WAFFRLI (2026-11-03) — order events for the customer (stored, live on
 * user.{id}, FCM). Identifiers only; the app re-reads the order.
 */
class CustomerOrderUpdate extends AppNotification
{
    public const TEXTS = [
        'accepted' => ['en' => 'Your order :n was accepted.', 'ar' => 'تم قبول طلبك :n.'],
        'assigned' => ['en' => 'A driver is on the way to pick up :n.', 'ar' => 'تم تعيين سائق لطلبك :n.'],
        'picked_up' => ['en' => 'Your order :n is on its way.', 'ar' => 'طلبك :n في الطريق إليك.'],
        'delivered' => ['en' => 'Your order :n was delivered.', 'ar' => 'تم توصيل طلبك :n.'],
        'cancelled' => ['en' => 'Your order :n was cancelled.', 'ar' => 'تم إلغاء طلبك :n.'],
        'cancellation_rejected' => ['en' => 'Your cancellation request for :n was declined.', 'ar' => 'تم رفض طلب إلغاء :n.'],
        'refunded' => ['en' => 'A refund was recorded for :n.', 'ar' => 'تم تسجيل استرداد للطلب :n.'],
    ];

    public function __construct(public string $kind, public Order $order) {}

    public function event(): string
    {
        return 'order.'.$this->kind;
    }

    protected function texts(): array
    {
        $body = array_map(fn ($t) => str_replace(':n', $this->order->order_number, $t), self::TEXTS[$this->kind]);

        return ['title' => ['en' => 'Order '.$this->order->order_number, 'ar' => 'الطلب '.$this->order->order_number], 'body' => $body];
    }

    protected function references(): array
    {
        return ['order_id' => $this->order->id, 'order_number' => $this->order->order_number];
    }
}
