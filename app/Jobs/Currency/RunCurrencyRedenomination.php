<?php

namespace App\Jobs\Currency;

use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * WAFFRLI (2026-11-03) — runs a pending default-currency switch. Unique per
 * run; transient lock errors are retried (the run stays pending).
 */
class RunCurrencyRedenomination implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CurrencyRedenominationService $service): void
    {
        $run = CurrencyRedenomination::query()->find($this->runId);
        if ($run && $run->status === CurrencyRedenomination::STATUS_PENDING) {
            $service->execute($run);
        }
    }
}
