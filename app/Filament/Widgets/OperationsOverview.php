<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\DriverCashSettlement;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PlusRequest;
use App\Support\Auth\AdminPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * WAFFRLI (2026-10-31) — live operational counters from the database.
 * Each stat is shown only to admins allowed to act on it.
 */
class OperationsOverview extends StatsOverviewWidget
{
    use \App\Filament\Concerns\RefreshesOnOrderEvents;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view')
            || AdminPermissions::currentAllows('plus.view')
            || AdminPermissions::currentAllows('settlements.manage');
    }

    protected function getStats(): array
    {
        $stats = [];

        if (AdminPermissions::currentAllows('orders.view')) {
            $stats[] = Stat::make(__('admin.dashboard.pending_orders'), Order::where('status', OrderStatus::Pending->value)->count())->color('warning');
            $stats[] = Stat::make(__('admin.dashboard.urgent_orders'), Order::where('status', OrderStatus::Pending->value)->whereNotNull('urgent_at')->count())->color('danger');
            $stats[] = Stat::make(__('admin.dashboard.active_deliveries'), Order::whereIn('status', OrderStatus::active())->count())->color('primary');
            $stats[] = Stat::make(__('admin.dashboard.delivered_today'), Order::where('status', OrderStatus::Delivered->value)->whereDate('delivered_at', today())->count())->color('success');
            $stats[] = Stat::make(__('admin.dashboard.pending_cancellation_requests'),
                OrderCancellationRequest::where('status', OrderCancellationRequest::STATUS_PENDING)->count());
        }

        if (AdminPermissions::currentAllows('plus.view')) {
            $stats[] = Stat::make(__('admin.dashboard.pending_plus_requests'), PlusRequest::where('status', PlusRequest::STATUS_PENDING)->count());
        }

        if (AdminPermissions::currentAllows('settlements.manage')) {
            $stats[] = Stat::make(__('admin.dashboard.open_settlements'),
                DriverCashSettlement::whereIn('status', [DriverCashSettlement::STATUS_OPEN, DriverCashSettlement::STATUS_DISPUTED])->count());
        }

        return $stats;
    }
}
