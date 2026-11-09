<?php

namespace App\Jobs\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Models\DeliveryOffer;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryOfferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — queued with a delay of the offer's
 * timeout when the offer is created. At the deadline: expires the offer
 * if it is still pending, then lets the dispatcher offer the order to the
 * next driver.
 *
 * Safe to run any number of times and at any moment: an early run puts
 * itself back until the deadline, a run after the driver answered only
 * nudges the dispatcher (which does nothing while another offer is
 * pending). If the job is lost (worker down, queue cleared), the
 * scheduled `waffrli:dispatch-sweep` expires the offer instead.
 */
class ExpireDeliveryOffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $offerId) {}

    public function handle(DeliveryOfferService $offers, DeliveryDispatcher $dispatcher): string
    {
        $offer = DeliveryOffer::query()->find($this->offerId);
        if (! $offer) {
            return 'missing';
        }
        if ($offer->status === DeliveryOfferStatus::Pending && ! $offer->isOverdue()) {
            // Ran early (clock skew, manual run): come back at the deadline.
            $this->release(max(1, (int) ceil(now()->diffInSeconds($offer->expires_at, true))));

            return 'early';
        }
        if ($offer->status === DeliveryOfferStatus::Pending) {
            $offers->expire($offer);
        }

        return $dispatcher->advance($offer->order_id);
    }
}
