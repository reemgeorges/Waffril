<?php

namespace App\Jobs\Push;

use App\Models\MarketingBroadcast;
use App\Services\Notifications\MarketingBroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** WAFFRLI (2026-11-03) — delivers one marketing broadcast (single attempt: never resent twice). */
class SendMarketingBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $broadcastId)
    {
        $this->onQueue(config('waffrli.push.queue', 'default'));
    }

    public function handle(MarketingBroadcastService $service): void
    {
        if ($broadcast = MarketingBroadcast::query()->find($this->broadcastId)) {
            $service->deliver($broadcast);
        }
    }
}
