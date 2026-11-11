<?php

namespace App\Filament\Pages;

use App\Support\Settings\PlatformSettings;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * WAFFRLI (2026-10-31) — operational dashboard (widgets in
 * app/Filament/Widgets, each permission-aware).
 *
 * Layout (redesign 2026-11-11), top to bottom: live operations, the pending
 * queue beside the status chart, finance per currency, platform size.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }

    /** Today's date in the platform timezone and the panel language. */
    public function getSubheading(): ?string
    {
        $locale = app()->getLocale();

        return now(PlatformSettings::timezone())->locale($locale)
            ->isoFormat($locale === 'ar' ? 'dddd، D MMMM YYYY' : 'dddd, D MMMM YYYY');
    }

    /** @return int|array<string, int> */
    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
