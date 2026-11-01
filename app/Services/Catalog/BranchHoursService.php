<?php

namespace App\Services\Catalog;

use App\Models\Branch;
use App\Models\BranchHourException;
use App\Support\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI (2026-10-31) — effective opening hours.
 *
 * Open at t  ⇔  branch active
 *            AND (a weekly slot OR an `open` exception covers t)
 *            AND no `closed` exception covers t.
 * Weekly slots are interpreted in the platform timezone; a slot whose
 * closes_at is earlier than opens_at runs past midnight.
 * `is_accepting_orders` is a separate instant pause for immediate orders.
 */
class BranchHoursService
{
    public function isOpenAt(Branch $branch, CarbonInterface $at): bool
    {
        if (! $branch->is_active) {
            return false;
        }

        // Uses eager-loaded relations when present (customer listings load
        // hourExceptions + openingHours once for many branches: no N+1).
        $exceptions = $branch->relationLoaded('hourExceptions')
            ? $branch->hourExceptions->filter(fn ($e) => $e->starts_at <= $at && $e->ends_at > $at)->pluck('kind')
            : BranchHourException::query()
                ->where('branch_id', $branch->id)
                ->where('starts_at', '<=', $at)
                ->where('ends_at', '>', $at)
                ->pluck('kind');

        if ($exceptions->contains(BranchHourException::KIND_CLOSED)) {
            return false;
        }

        return $exceptions->contains(BranchHourException::KIND_OPEN) || $this->weeklySlotCovers($branch, $at);
    }

    protected function weeklySlotCovers(Branch $branch, CarbonInterface $at): bool
    {
        $local = Carbon::instance($at)->setTimezone(PlatformSettings::timezone());
        $time = $local->format('H:i:s');
        $today = $local->dayOfWeek;
        $yesterday = ($today + 6) % 7;

        foreach (($branch->relationLoaded('openingHours') ? $branch->openingHours : $branch->openingHours()->get()) as $slot) {
            $opens = Carbon::parse($slot->opens_at)->format('H:i:s');
            $closes = Carbon::parse($slot->closes_at)->format('H:i:s');
            $overnight = $closes < $opens;

            if ($slot->day_of_week === $today && ($overnight ? $time >= $opens : ($time >= $opens && $time < $closes))) {
                return true;
            }

            if ($overnight && $slot->day_of_week === $yesterday && $time < $closes) {
                return true;
            }
        }

        return false;
    }
}
