<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * WAFFRLI (2026-10-31) — operational dashboard (widgets in
 * app/Filament/Widgets, each permission-aware).
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }
}
