<?php

namespace App\Events\Realtime;

use App\Http\Resources\Model\Driver\DriverOfferResource;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Traits\Localizable;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — a delivery offer changed, sent ONLY
 * to the offered driver on `private-driver.{id}` (ChannelAccess::driver
 * authorizes a driver for their own channel only):
 *
 *   offer.created    a new offer to answer
 *   offer.accepted   this driver accepted (other devices close the offer)
 *   offer.withdrawn  rejected, expired or cancelled: close it
 *
 * Published through Realtime::publish, i.e. after the transaction commits.
 * Payload: `status` is the state at the moment of the change; `offer` is
 * the same DriverOfferResource as the REST API (privacy-limited, rendered
 * in the driver's language when the event is sent). Events can arrive
 * late, twice or out of order: clients key on offer.id and keep the most
 * advanced status (any final status wins over `pending`), and re-read
 * GET /driver/offers/current when unsure.
 */
class DeliveryOfferChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, Localizable;

    public const CREATED = 'offer.created';

    public const ACCEPTED = 'offer.accepted';

    public const WITHDRAWN = 'offer.withdrawn';

    public function __construct(
        public string $name,
        public int $offerId,
        public int $driverId,
        public int $orderId,
        public string $status,
        public ?string $reason,
        public string $at,
    ) {}

    public static function for(string $name, DeliveryOffer $offer): self
    {
        return new self(
            $name, (int) $offer->id, (int) $offer->driver_id, (int) $offer->order_id, $offer->status->value,
            $offer->reject_reason ?? $offer->cancel_reason, now()->toIso8601String(),
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        $offer = DeliveryOffer::query()->with(DriverOfferResource::WITH)->find($this->offerId);
        $locale = Driver::query()->whereKey($this->driverId)->value('language') ?: config('app.locale');

        return [
            'event' => $this->name,
            'offer_id' => $this->offerId,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'reason' => $this->reason,
            'at' => $this->at,
            'offer' => $offer ? $this->withLocale($locale, fn () => DriverOfferResource::make($offer)->resolve()) : null,
        ];
    }
}
