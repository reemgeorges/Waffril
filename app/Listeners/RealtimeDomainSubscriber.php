<?php

namespace App\Listeners;

use App\Events\Orders\CancellationRequested;
use App\Events\Orders\OrderPlaced;
use App\Events\Plus\PlusRequestSubmitted;
use App\Events\Realtime\AdminOperationalEvent;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Services\Realtime\Realtime;
use Illuminate\Events\Dispatcher;

/**
 * WAFFRLI (2026-11-03) — turns customer-side domain events into live
 * operations alerts on `admin.orders`. Registered with Event::subscribe in
 * AppServiceProvider (methods are named on*, so listener auto-discovery
 * never registers them a second time).
 */
class RealtimeDomainSubscriber
{
    public function onOrderPlaced(OrderPlaced $event): void
    {
        Realtime::publish(new AdminOperationalEvent('order.placed', $event->orderId, Order::query()->whereKey($event->orderId)->value('order_number')));
    }

    public function onCancellationRequested(CancellationRequested $event): void
    {
        $orderId = OrderCancellationRequest::query()->whereKey($event->requestId)->value('order_id');
        Realtime::publish(new AdminOperationalEvent('order.cancellation_requested', $event->requestId, $orderId ? Order::query()->whereKey($orderId)->value('order_number') : null));
    }

    public function onPlusRequestSubmitted(PlusRequestSubmitted $event): void
    {
        Realtime::publish(new AdminOperationalEvent('plus.request_submitted', $event->requestId));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'onOrderPlaced',
            CancellationRequested::class => 'onCancellationRequested',
            PlusRequestSubmitted::class => 'onPlusRequestSubmitted',
        ];
    }
}
