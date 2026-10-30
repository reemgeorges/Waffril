<?php

namespace App\Console\Commands\Waffrli;

use App\Services\Order\OrderAdminService;
use Illuminate\Console\Command;

/**
 * WAFFRLI (2026-10-31) — flags late scheduled orders as urgent. They stay
 * pending; nothing is cancelled automatically.
 */
class FlagUrgentOrdersCommand extends Command
{
    protected $signature = 'waffrli:flag-urgent-orders';

    protected $description = 'Flag pending scheduled orders whose time has passed as urgent (never cancels them)';

    public function handle(OrderAdminService $orders): int
    {
        $this->info('Flagged: '.$orders->flagLateScheduledOrders());

        return self::SUCCESS;
    }
}
