<?php

namespace App\Services\Customer;

use App\Enums\OrderStatus;
use App\Events\Orders\CancellationRequested;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\User;
use App\Services\Order\OrderAdminService;
use App\Support\Localization\Label;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI customer API (2026-11-03) — the customer's own orders.
 *
 * - Ownership: other customers' orders are 404.
 * - Customers cancel DIRECTLY only while the order is pending (before an
 *   admin accepts it; wallet payments are reversed by the shared service).
 *   After acceptance they can only send ONE cancellation request; an admin
 *   decides. Submitted orders are never edited.
 * - Driver details are limited to what the delivery needs (name and
 *   vehicle, only while assigned / picked up); live position arrives on the
 *   order's private channel.
 */
class CustomerOrderService
{
    public const WITH = ['branch.store', 'currency', 'items.options', 'payments.method', 'statusHistories', 'cancellation', 'cancellationRequests', 'refunds', 'driver'];

    public function own(User $user, int|string $orderId): Order
    {
        $order = Order::query()->whereKey($orderId)->where('user_id', $user->id)->with(self::WITH)->first();
        if (! $order) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }

        return $order;
    }

    public function list(User $user, string $scope, int $perPage)
    {
        $active = [OrderStatus::Pending->value, OrderStatus::Accepted->value, OrderStatus::Assigned->value, OrderStatus::PickedUp->value];

        return Order::query()->where('user_id', $user->id)
            ->when($scope === 'current', fn ($q) => $q->whereIn('status', $active))
            ->when($scope === 'previous', fn ($q) => $q->whereNotIn('status', $active))
            ->with(self::WITH)->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function cancel(User $user, int|string $orderId, ?string $note): Order
    {
        $order = $this->own($user, $orderId);
        if ($order->status !== OrderStatus::Pending) {
            throw new BusinessRuleException(__('messages.customer.cancel_not_allowed'), 409);
        }
        app(OrderAdminService::class)->cancelByCustomer($order, $note, 'customer:'.$order->id.':cancel');

        return $this->own($user, $orderId);
    }

    public function requestCancellation(User $user, int|string $orderId, ?string $note): OrderCancellationRequest
    {
        return DB::transaction(function () use ($user, $orderId, $note) {
            $order = Order::query()->whereKey($orderId)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $order) {
                throw new BusinessRuleException(__('messages.customer.not_found'), 404);
            }
            $allowed = in_array($order->status, [OrderStatus::Accepted, OrderStatus::Assigned, OrderStatus::PickedUp], true);
            $pending = OrderCancellationRequest::query()->where('order_id', $order->id)->where('status', OrderCancellationRequest::STATUS_PENDING)->exists();
            if (! $allowed || $pending) {
                throw new BusinessRuleException(__('messages.customer.cancel_request_not_allowed'), 409);
            }

            $request = OrderCancellationRequest::create(['order_id' => $order->id, 'customer_note' => $note]);
            DB::afterCommit(fn () => event(new CancellationRequested($request->id)));

            return $request;
        });
    }

    /** Customer view of an order (no other people's data beyond the delivery needs). */
    public function present(Order $order, bool $detailed = true): array
    {
        $c = $order->currency;
        $fmt = fn ($v) => (string) Money::roundTo((string) $v, $c->decimals);
        $active = in_array($order->status, [OrderStatus::Assigned, OrderStatus::PickedUp], true);

        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'type' => $order->type,
            'type_label' => Label::of('order_type', $order->type),
            'scheduled_for' => $order->scheduled_for?->toIso8601String(),
            'branch' => ['id' => $order->branch?->id, 'name' => $order->branch?->name, 'store' => ['id' => $order->branch?->store?->id, 'name' => $order->branch?->store?->name]],
            'currency' => ['code' => $c->code, 'symbol' => $c->symbol, 'decimals' => $c->decimals],
            'total' => $fmt($order->total),
            'created_at' => $order->created_at?->toIso8601String(),
            'can_cancel' => $order->status === OrderStatus::Pending,
            'can_request_cancellation' => in_array($order->status, [OrderStatus::Accepted, OrderStatus::Assigned, OrderStatus::PickedUp], true)
                && ! $order->cancellationRequests->contains('status', OrderCancellationRequest::STATUS_PENDING),
        ];
        if (! $detailed) {
            return $data;
        }

        return $data + [
            'items' => $order->items->map(fn ($i) => [
                'product_id' => $i->product_id, 'name' => $i->product_name, 'quantity' => $i->quantity,
                'unit_price' => $fmt($i->unit_price), 'line_total' => $fmt($i->line_total), 'notes' => $i->notes,
                'options' => $i->options->map(fn ($o) => ['group' => $o->group_name, 'option' => $o->option_name, 'quantity' => $o->quantity, 'total_price_delta' => $fmt($o->total_price_delta)])->values(),
            ])->values(),
            'amounts' => [
                'subtotal' => $fmt($order->subtotal), 'offer_discount' => $fmt($order->offer_discount_total), 'plus_discount' => $fmt($order->plus_discount_amount),
                'delivery_fee_original' => $fmt($order->delivery_fee_original), 'delivery_fee' => $fmt($order->delivery_fee),
                'free_delivery_applied' => (bool) $order->free_delivery_applied, 'total' => $fmt($order->total),
            ],
            'payments' => $order->payments->map(fn ($p) => [
                'method' => $p->method?->code, 'method_label' => $p->method?->name, 'amount' => $fmt($p->amount), 'status' => $p->status, 'status_label' => Label::of('payment_status', $p->status),
                'collected' => $fmt($p->collected_amount), 'reversed' => $fmt($p->reversed_amount), 'refunded' => $fmt($p->refunded_amount),
            ])->values(),
            'refunds' => $order->refunds->map(fn ($r) => ['amount' => $fmt($r->amount), 'reason' => $r->reason_code, 'reason_label' => Label::of('cancellation_reason', $r->reason_code), 'created_at' => $r->created_at?->toIso8601String()])->values(),
            'delivery' => [
                'address' => $order->delivery_address, 'lat' => $order->delivery_lat !== null ? (float) $order->delivery_lat : null,
                'lng' => $order->delivery_lng !== null ? (float) $order->delivery_lng : null, 'distance_km' => (float) $order->delivery_distance_km,
                'notes' => $order->customer_notes,
            ],
            'driver' => $active && $order->driver ? ['first_name' => strtok((string) $order->driver->name, ' '), 'vehicle_type' => $order->driver->vehicle_type] : null,
            'realtime_channel' => $active || $order->status === OrderStatus::Pending || $order->status === OrderStatus::Accepted ? 'order.'.$order->id : null,
            'status_history' => $order->statusHistories->sortBy('id')->map(fn ($h) => ['from' => $h->from_status, 'from_label' => $h->from_status ? OrderStatus::tryFrom($h->from_status)?->label() : null, 'to' => $h->to_status, 'to_label' => OrderStatus::tryFrom((string) $h->to_status)?->label(), 'at' => $h->created_at?->toIso8601String()])->values(),
            'cancellation' => $order->cancellation ? ['by' => $order->cancellation->cancelled_by, 'by_label' => Label::of('order_actor', $order->cancellation->cancelled_by), 'reason' => $order->cancellation->reason_code, 'reason_label' => Label::of('cancellation_reason', $order->cancellation->reason_code), 'at' => $order->cancellation->created_at?->toIso8601String()] : null,
            'cancellation_requests' => $order->cancellationRequests->map(fn ($r) => ['status' => $r->status, 'status_label' => Label::of('request_status', $r->status), 'note' => $r->customer_note, 'admin_note' => $r->admin_note, 'created_at' => $r->created_at?->toIso8601String()])->values(),
        ];
    }
}
