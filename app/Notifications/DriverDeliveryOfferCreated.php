<?php

namespace App\Notifications;

use App\Models\DeliveryOffer;
use App\Models\Order;
use App\Notifications\Channels\PushChannel;
use Ramsey\Uuid\Uuid;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — FCM push for a new delivery offer.
 *
 * Push only: the offer itself reaches an online driver as the Reverb
 * `offer.created` event (and PushChannel skips devices whose driver.{id}
 * channel is occupied), and offers are not stored in the notification
 * list. One push per active device token (drivers have up to 3).
 *
 * - Deterministic notification id per offer, so a repeated dispatch is
 *   deduplicated by SendPushMessage (one push per offer and device).
 * - `expires_at` travels with the message: a queued retry after the
 *   deadline is dropped, and FCM gets a matching TTL.
 * - Data: identifiers and the deadline only; never customer data.
 */
class DriverDeliveryOfferCreated extends AppNotification
{
    public function __construct(public DeliveryOffer $offer, public Order $order)
    {
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'waffrli:delivery-offer:'.$offer->id.':created')->toString();
    }

    public function via(object $notifiable): array
    {
        return [PushChannel::class];
    }

    public function event(): string
    {
        return 'driver.offer_created';
    }

    protected function texts(): array
    {
        return [
            'title' => $this->both('notifications.driver.offer_title'),
            'body' => $this->both('notifications.driver.offer_body', [
                'number' => $this->order->order_number,
                'seconds' => (int) $this->offer->offered_at->diffInSeconds($this->offer->expires_at, true),
            ]),
        ];
    }

    protected function references(): array
    {
        return [
            'offer_id' => $this->offer->id,
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'expires_at' => $this->offer->expires_at->toIso8601String(),
        ];
    }

    public function toPush(object $notifiable): array
    {
        return parent::toPush($notifiable) + ['expires_at' => $this->offer->expires_at->toIso8601String()];
    }
}
