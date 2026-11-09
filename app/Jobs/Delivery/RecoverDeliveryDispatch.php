<?php

namespace App\Jobs\Delivery;

use App\Services\Delivery\DeliveryDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — a driver became available: retry the
 * orders waiting in the no-driver queue right away instead of waiting for
 * the next sweep. Same rules and limits as the sweep (DeliveryDispatcher::recover).
 */
class RecoverDeliveryDispatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function handle(DeliveryDispatcher $dispatcher): array
    {
        return $dispatcher->recover((int) config('dispatch.sweep_batch', 50));
    }
}
