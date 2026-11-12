<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\DriverCashSettlements\DriverCashSettlementResource;
use App\Filament\Resources\OrderCancellationRequests\OrderCancellationRequestResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PlusRequests\PlusRequestResource;
use App\Models\DriverCashSettlement;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PlusRequest;
use App\Services\Delivery\DeliveryOfferService;
use App\Support\Auth\AdminPermissions;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI dashboard, "Needs your attention" (UX redesign 2026-11-11;
 * counters since 2026-10-31). Everything an employee must act on, read live
 * from the database, most urgent first. A tile with work shows its colour,
 * an icon and the words "Needs action" (never colour alone) and links to
 * the exact list (an order tab or a pre-filtered list); a tile with nothing
 * to do stays quiet. Each tile is shown only to admins allowed to act on it,
 * and its link only when the target page is accessible.
 */
class OperationsOverview extends Widget
{
    use RefreshesOnOrderEvents;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.attention-overview';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view')
            || AdminPermissions::currentAllows('plus.view')
            || AdminPermissions::currentAllows('settlements.manage');
    }

    /**
     * @return list<array{key: string, label: string, count: int, hint: ?string, tone: string, icon: string, url: ?string}>
     */
    public static function items(): array
    {
        $items = [];

        if (AdminPermissions::currentAllows('orders.view')) {
            $pending = Order::query()->where('status', OrderStatus::Pending->value);
            $oldest = (clone $pending)->min('created_at');
            $items[] = self::item('pending_orders', __('admin.dashboard.pending_orders'), (clone $pending)->count(), 'warning', 'heroicon-o-inbox-arrow-down',
                $oldest ? __('admin.dashboard.oldest_waiting', ['time' => Carbon::parse($oldest)->locale(app()->getLocale())->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE)]) : null,
                OrderResource::getUrl('index', ['tab' => 'pending']));

            $items[] = self::item('urgent_orders', __('admin.dashboard.urgent_orders'),
                Order::query()->where('status', OrderStatus::Pending->value)->whereNotNull('urgent_at')->count(), 'danger', 'heroicon-o-exclamation-triangle',
                __('admin.dashboard.urgent_hint'), OrderResource::getUrl('index', ['tab' => 'urgent']));

            $needsDriver = DeliveryOfferService::enabled()
                ? Order::query()->where('status', OrderStatus::Accepted->value)->whereIn('dispatch_state', [Order::DISPATCH_OFFERING, Order::DISPATCH_NO_DRIVER])->count()
                : Order::query()->where('status', OrderStatus::Accepted->value)->whereNull('driver_id')->count();
            $items[] = self::item('needs_driver', __('admin.dashboard.needs_driver'), $needsDriver, 'info', 'heroicon-o-user-plus',
                __('admin.dashboard.needs_driver_hint'),
                OrderResource::getUrl('index', ['tab' => DeliveryOfferService::enabled() ? 'waiting_for_driver' : 'needs_driver']));

            $items[] = self::item('pending_cancellation_requests', __('admin.dashboard.pending_cancellation_requests'),
                OrderCancellationRequest::query()->where('status', OrderCancellationRequest::STATUS_PENDING)->count(), 'danger', 'heroicon-o-hand-raised',
                __('admin.dashboard.cancellation_hint'),
                OrderCancellationRequestResource::canViewAny() ? OrderCancellationRequestResource::getUrl('index') : null);
        }

        if (AdminPermissions::currentAllows('plus.view')) {
            $items[] = self::item('pending_plus_requests', __('admin.dashboard.pending_plus_requests'),
                PlusRequest::query()->where('status', PlusRequest::STATUS_PENDING)->count(), 'gold', 'heroicon-o-star',
                __('admin.dashboard.plus_hint'), PlusRequestResource::canViewAny() ? PlusRequestResource::getUrl('index') : null);
        }

        if (AdminPermissions::currentAllows('settlements.manage')) {
            $items[] = self::item('open_settlements', __('admin.dashboard.open_settlements'),
                DriverCashSettlement::query()->whereIn('status', [DriverCashSettlement::STATUS_OPEN, DriverCashSettlement::STATUS_DISPUTED])->count(), 'warning', 'heroicon-o-banknotes',
                __('admin.dashboard.settlements_hint', ['disputed' => DriverCashSettlement::query()->where('status', DriverCashSettlement::STATUS_DISPUTED)->count()]),
                DriverCashSettlementResource::canViewAny() ? DriverCashSettlementResource::getUrl('index') : null);
        }

        return $items;
    }

    /** Live deliveries (assigned or picked up): shown beside the tiles. */
    public static function activeDeliveries(): ?int
    {
        return AdminPermissions::currentAllows('orders.view')
            ? Order::query()->whereIn('status', OrderStatus::active())->count()
            : null;
    }

    protected static function item(string $key, string $label, int $count, string $tone, string $icon, ?string $hint, ?string $url): array
    {
        return compact('key', 'label', 'count', 'tone', 'icon', 'hint', 'url');
    }

    protected function getViewData(): array
    {
        $items = self::items();

        return [
            'items' => $items,
            'open' => array_sum(array_column($items, 'count')),
            'active' => self::activeDeliveries(),
            'activeUrl' => OrderResource::getUrl('index', ['tab' => 'on_the_road']),
        ];
    }
}
