<?php

namespace App\Filament\Support;

use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * UX overhaul (2026-11-12): what a customer's WAFFRLI PLUS situation really
 * is, for lists and profiles. "Active" means benefits apply now: an active
 * cycle exists (a cancelled membership keeps its current cycle until it
 * ends). A waiting request is shown separately and never as membership.
 * Read-only; PLUS rules stay in App\Services\Plus\PlusService.
 */
final class PlusStatus
{
    public const ACTIVE = 'active';

    public const ENDING = 'ending';

    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const NONE = 'none';

    /** Eager loads for a customer list (no N+1). */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->with(['plusMembership.plan', 'plusMembership.activeCycle'])
            ->withCount(['plusRequests as pending_plus_requests_count' => fn (Builder $q) => $q->where('status', PlusRequest::STATUS_PENDING)]);
    }

    /** @return array{state: string, label: string, color: string, icon: string, plan: ?string, cycle: ?PlusCycle, membership: ?PlusMembership, pending: int} */
    public static function for(User $user): array
    {
        $membership = $user->plusMembership;
        $cycle = $membership?->activeCycle;
        $pending = $user->pending_plus_requests_count
            ?? $user->plusRequests()->where('status', PlusRequest::STATUS_PENDING)->count();

        $state = match (true) {
            $cycle !== null && $membership->status === PlusMembership::STATUS_ACTIVE => self::ACTIVE,
            $cycle !== null => self::ENDING,
            $membership?->status === PlusMembership::STATUS_ACTIVE => self::SCHEDULED,
            $pending > 0 => self::PENDING,
            default => self::NONE,
        };

        return [
            'state' => $state,
            'label' => __("admin.plus_status.{$state}"),
            'color' => [self::ACTIVE => 'success', self::ENDING => 'warning', self::SCHEDULED => 'info', self::PENDING => 'gold', self::NONE => 'gray'][$state],
            'icon' => [self::ACTIVE => 'heroicon-m-star', self::ENDING => 'heroicon-m-clock', self::SCHEDULED => 'heroicon-m-calendar', self::PENDING => 'heroicon-m-inbox', self::NONE => 'heroicon-m-minus'][$state],
            'plan' => $cycle?->plan_name ? (is_array($cycle->plan_name) ? ($cycle->plan_name[app()->getLocale()] ?? reset($cycle->plan_name)) : $cycle->plan_name) : $membership?->plan?->name,
            'cycle' => $cycle,
            'membership' => $membership,
            'pending' => (int) $pending,
        ];
    }

    /** Query scope for the customer list filter. */
    public static function scope(Builder $query, ?string $state): Builder
    {
        $activeCycle = fn (Builder $m) => $m->whereHas('cycles', fn (Builder $c) => $c->where('status', PlusCycle::STATUS_ACTIVE));

        return match ($state) {
            self::ACTIVE => $query->whereHas('plusMembership', $activeCycle),
            self::PENDING => $query->whereHas('plusRequests', fn (Builder $r) => $r->where('status', PlusRequest::STATUS_PENDING)),
            self::NONE => $query->whereDoesntHave('plusMembership', $activeCycle),
            default => $query,
        };
    }
}
