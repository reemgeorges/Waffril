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
     * Dispatch B4 (2026-11-10): "Waiting for driver" queue (offers out or no
     * driver available), orders needing attention first. Only shown while
     * delivery offers are enabled or such orders exist.
     */
    public function getTabs(): array
    {
        $waiting = fn (Builder $query) => $query->where('status', OrderStatus::Accepted->value)
            ->whereIn('dispatch_state', [Order::DISPATCH_OFFERING, Order::DISPATCH_NO_DRIVER]);
        $count = $waiting(Order::query())->count();
        if (! DeliveryOfferService::enabled() && $count === 0) {
            return [];
        }
        $noDriver = Order::query()->where('status', OrderStatus::Accepted->value)->where('dispatch_state', Order::DISPATCH_NO_DRIVER)->count();

        return [
            'all' => Tab::make(__('admin.dispatch.tab_all')),
            'waiting_for_driver' => Tab::make(__('admin.dispatch.queue'))
                ->icon('heroicon-o-clock')
                ->badge($count ?: null)->badgeColor($noDriver > 0 ? 'danger' : 'warning')
                ->modifyQueryUsing(fn (Builder $query) => $waiting($query)->with(['branch.store', 'latestDeliveryOffer'])
                    ->reorder()->orderByRaw("CASE WHEN dispatch_state = 'no_driver' THEN 0 ELSE 1 END")->orderBy('created_at')),
        ];
    }
}
