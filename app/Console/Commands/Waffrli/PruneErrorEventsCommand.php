<?php

namespace App\Console\Commands\Waffrli;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;

/**
 * WAFFRLI (2026-11-16, Phase 9) — error retention. Deletes error groups that
 * are resolved/ignored and not seen for `resolved_retention_days`, and any
 * group not seen for `stale_retention_days` (config/waffrli.php monitoring).
 * Only the error_events table; scheduled daily.
 */
class PruneErrorEventsCommand extends Command
{
    protected $signature = 'waffrli:prune-errors {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete old resolved/ignored error groups and long-silent ones (retention)';

    public function handle(): int
    {
        $resolvedDays = max(1, (int) config('waffrli.monitoring.resolved_retention_days', 30));
        $staleDays = max($resolvedDays, (int) config('waffrli.monitoring.stale_retention_days', 90));

        $query = ErrorEvent::query()->where(fn ($q) => $q
            ->where(fn ($r) => $r->whereIn('status', [ErrorEvent::STATUS_RESOLVED, ErrorEvent::STATUS_IGNORED])->where('last_seen_at', '<', now()->subDays($resolvedDays)))
            ->orWhere('last_seen_at', '<', now()->subDays($staleDays)));

        $count = (clone $query)->count();
        if (! $this->option('dry-run')) {
            $query->delete();
        }
        $this->info(($this->option('dry-run') ? 'Would delete' : 'Deleted')." {$count} error group(s) (resolved/ignored > {$resolvedDays} days, any > {$staleDays} days).");

        return self::SUCCESS;
    }
}
