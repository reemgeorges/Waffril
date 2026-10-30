<?php

namespace App\Console\Commands\Waffrli;

use App\Services\Plus\PlusService;
use Illuminate\Console\Command;

/**
 * WAFFRLI (2026-10-31) — expires/activates PLUS cycles, ends finished
 * memberships and raises system renewal REQUESTS (an admin still confirms
 * every cycle).
 */
class SyncPlusCyclesCommand extends Command
{
    protected $signature = 'waffrli:sync-plus-cycles';

    protected $description = 'Expire/activate PLUS cycles and raise renewal requests for admin confirmation';

    public function handle(PlusService $plus): int
    {
        $stats = $plus->syncCycles();
        $this->info(json_encode($stats));

        return self::SUCCESS;
    }
}
