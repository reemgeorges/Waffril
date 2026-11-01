<?php

namespace App\Console\Commands\Waffrli;

use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use Illuminate\Console\Command;

/**
 * WAFFRLI (2026-11-03) — resumes a PENDING default-currency switch (for
 * example after a worker crash: the conversion transaction rolled back and
 * the run is still pending). Completed and failed runs are never re-run.
 */
class ResumeCurrencyRedenominationCommand extends Command
{
    protected $signature = 'waffrli:currency-switch:resume {run : currency_redenominations.id}';

    protected $description = 'Run a pending default-currency switch (idempotent; completed runs are left unchanged).';

    public function handle(CurrencyRedenominationService $service): int
    {
        $run = CurrencyRedenomination::query()->find($this->argument('run'));
        if (! $run) {
            $this->error('Run not found.');

            return self::FAILURE;
        }
        if ($run->status !== CurrencyRedenomination::STATUS_PENDING) {
            $this->info("Run {$run->id} is {$run->status}; nothing to do.");

            return self::SUCCESS;
        }

        $run = $service->execute($run);
        $this->info("Run {$run->id}: {$run->status}.");

        return $run->status === CurrencyRedenomination::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
