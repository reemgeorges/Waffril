<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Concerns\RefreshesOnOrderEvents;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use App\Support\Auth\AdminPermissions;
use Filament\Widgets\Widget;

/**
 * WAFFRLI dashboard, "Order pipeline" (UX redesign 2026-11-11; status counts
 * since 2026-11-01). Where today's open work stands, stage by stage (waiting
 * for acceptance → accepted → driver assigned → on the way), each stage a
 * link to its list. Closed orders (delivered, cancelled) are totals over all
 * time and shown as a quiet footer, so they never dwarf the live stages.
 */
class OrdersByStatusChart extends Widget
{
    use RefreshesOnOrderEvents;

    protected static ?int $sort = 3;

    /** One third of a wide dashboard, beside the 14-day trend. */
    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected string $view = 'filament.widgets.order-pipeline';

    public static function canView(): bool
    {
        return AdminPermissions::currentAllows('orders.view');
    }

    /** @return array<string, int> status value => count, every status present */
    public static function counts(): array
    {
        $counts = Order::query()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    protected function getViewData(): array
    {
        $counts = self::counts();
        $tabs = ['pending' => 'pending', 'accepted' => DeliveryOfferService::enabled() ? 'waiting_for_driver' : 'needs_driver', 'assigned' => 'on_the_road', 'picked_up' => 'on_the_road'];
        $open = ['pending', 'accepted', 'assigned', 'picked_up'];
        $max = max(1, ...array_map(fn ($s) => $counts[$s], $open));

        return [
            'stages' => array_map(fn (string $status) => [
                'status' => $status,
                'label' => OrderStatus::from($status)->label(),
                'count' => $counts[$status],
                'share' => (int) round($counts[$status] / $max * 100),
                'url' => OrderResource::getUrl('index', ['tab' => $tabs[$status]]),
            ], $open),
            'open' => array_sum(array_map(fn ($s) => $counts[$s], $open)),
            'delivered' => $counts['delivered'],
            'cancelled' => $counts['cancelled'],
            'allUrl' => OrderResource::getUrl('index'),
        ];
    }
}
