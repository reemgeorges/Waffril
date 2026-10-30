<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — the single way business code publishes to the ONE
 * shared Reverb server.
 *
 * - Runs after the surrounding transaction commits (never announces data
 *   that is rolled back), immediately when there is none.
 * - Events are ShouldBroadcast (queued), so a slow or unavailable WebSocket
 *   server never delays or fails an order, a payment or a GPS update; a
 *   failure to enqueue is logged, not thrown.
 */
class Realtime
{
    public static function publish(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            try {
                broadcast($event);
            } catch (Throwable $e) {
                Log::warning('realtime.publish_failed', ['event' => $event::class, 'error' => $e->getMessage()]);
            }
        });
    }
}
