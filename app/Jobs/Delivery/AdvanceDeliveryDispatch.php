<?php

namespace App\Jobs\Delivery;

use App\Services\Delivery\DeliveryDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — after a rejection or a withdrawn
 * offer: offer the order to the next driver (DeliveryDispatcher::advance).
 * Dispatched after the transaction commits; idempotent, so duplicates and
 * the scheduled sweep running at the same time are harmless.
 */
class AdvanceDeliveryDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public function handle(DeliveryDispatcher $dispatcher): string
    {
        return $dispatcher->advance($this->orderId);
    }
}
