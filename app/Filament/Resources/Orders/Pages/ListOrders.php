<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    use RefreshesOnOrderEvents;

    protected static string $resource = OrderResource::class;

    /**
     * UX redesign (2026-11-11): the order list is organised by the next step
     * an employee takes (accept → find a driver → follow the delivery), each
     * tab with its live count. Tabs only filter the query; the order rules
     * stay in the services. The dashboard links straight to these tabs
     * (?tab=pending, …).
     *
     * Dispatch B4 (2026-11-10): the "Waiting for driver" queue (offers out or
     * no driver available, orders needing attention first) is shown while
     * delivery offers are enabled or such orders exist.
     */
    public function getTabs(): array
    {
        $count = fn (callable $scope) => $scope(Order::query())->count() ?: null;

        $pending = fn (Builder $query) => $query->where('status', OrderStatus::Pending->value);
        $urgent = fn (Builder $query) => $query->where('status', OrderStatus::Pending->value)->whereNotNull('urgent_at');
        $needsDriver = fn (Builder $query) => $query->where('status', OrderStatus::Accepted->value)->whereNull('driver_id');
        $onTheRoad = fn (Builder $query) => $query->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value]);

        $tabs = [
            'all' => Tab::make(__('admin.dispatch.tab_all')),
            'pending' => Tab::make(__('admin.orders.tab_pending'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge($count($pending))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $pending($query)
                    ->reorder()->orderByRaw('CASE WHEN urgent_at IS NULL THEN 1 ELSE 0 END')->orderBy('created_at')),
        ];

        $urgentCount = $count($urgent);
        if ($urgentCount) {
            $tabs['urgent'] = Tab::make(__('admin.orders.tab_urgent'))
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($urgentCount)->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $urgent($query)->reorder()->orderBy('urgent_at'));
        }

        // Dispatch B4 queue, unchanged.
        $waiting = fn (Builder $query) => $query->where('status', OrderStatus::Accepted->value)
            ->whereIn('dispatch_state', [Order::DISPATCH_OFFERING, Order::DISPATCH_NO_DRIVER]);
        $waitingCount = $waiting(Order::query())->count();
        if (DeliveryOfferService::enabled() || $waitingCount > 0) {
            $noDriver = Order::query()->where('status', OrderStatus::Accepted->value)->where('dispatch_state', Order::DISPATCH_NO_DRIVER)->count();
            $tabs['waiting_for_driver'] = Tab::make(__('admin.dispatch.queue'))
                ->icon('heroicon-o-clock')
                ->badge($waitingCount ?: null)->badgeColor($noDriver > 0 ? 'danger' : 'warning')
                ->modifyQueryUsing(fn (Builder $query) => $waiting($query)->with(['branch.store', 'latestDeliveryOffer'])
                    ->reorder()->orderByRaw("CASE WHEN dispatch_state = 'no_driver' THEN 0 ELSE 1 END")->orderBy('created_at'));
        }

        if (! DeliveryOfferService::enabled()) {
            // Direct assignment: accepted orders still without a driver.
            $tabs['needs_driver'] = Tab::make(__('admin.orders.tab_needs_driver'))
                ->icon('heroicon-o-user-plus')
                ->badge($count($needsDriver))->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $needsDriver($query)->reorder()->orderBy('accepted_at'));
        }

        $tabs['on_the_road'] = Tab::make(__('admin.orders.tab_on_the_road'))
            ->icon('heroicon-o-truck')
            ->badge($count($onTheRoad))->badgeColor('transit')
            ->modifyQueryUsing($onTheRoad);
        $tabs['delivered'] = Tab::make(__('admin.order_status.delivered'))
            ->icon('heroicon-o-check-circle')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Delivered->value));
        $tabs['cancelled'] = Tab::make(__('admin.order_status.cancelled'))
            ->icon('heroicon-o-x-circle')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Cancelled->value));

        return $tabs;
    }
}
