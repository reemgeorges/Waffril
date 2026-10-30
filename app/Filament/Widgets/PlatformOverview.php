<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Auth\AdminPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * WAFFRLI (2026-11-01) — platform size at a glance, straight from the
 * database; each stat is shown only to admins allowed to see that area.
 */
class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('customers.view')
            || AdminPermissions::currentAllows('catalog.view')
            || AdminPermissions::currentAllows('plus.view');
    }

    protected function getStats(): array
    {
        $stats = [];

        if (AdminPermissions::currentAllows('customers.view')) {
            $stats[] = Stat::make(__('admin.dashboard_stats.customers'), User::query()->count())
                ->description(__('admin.dashboard_stats.customers_desc', ['suspended' => User::query()->where('account_status', User::ACCOUNT_SUSPENDED)->count()]))
                ->icon('heroicon-o-users');
        }

        if (AdminPermissions::currentAllows('catalog.view')) {
            $stats[] = Stat::make(__('admin.dashboard_stats.stores'), Store::query()->count().' / '.Branch::query()->count())
                ->description(__('admin.dashboard_stats.stores_desc', ['branches' => Branch::query()->where('is_active', true)->count()]))
                ->icon('heroicon-o-building-storefront');
            $stats[] = Stat::make(__('admin.dashboard_stats.products'), Product::query()->count())
                ->description(__('admin.dashboard_stats.products_desc', ['active' => Product::query()->where('is_active', true)->count()]))
                ->icon('heroicon-o-cube');
        }

        if (AdminPermissions::currentAllows('plus.view')) {
            $stats[] = Stat::make(__('admin.dashboard_stats.plus_active'), PlusMembership::query()->where('status', PlusMembership::STATUS_ACTIVE)->count())
                ->description(__('admin.dashboard_stats.plus_desc', ['cycles' => PlusCycle::query()->where('status', PlusCycle::STATUS_ACTIVE)->count()]))
                ->icon('heroicon-o-star')->color('success');
        }

        return $stats;
    }
}
