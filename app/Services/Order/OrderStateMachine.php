<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * WAFFRLI (2026-10-31) — the single place where an order's status
 * changes. Each change is a guarded UPDATE (`WHERE status = :from`), so
 * two concurrent admins can never both apply a transition, and always
 * writes one append-only history row. Callers wrap it in their own
 * transaction together with the transition's side effects.
 */
class OrderStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'pending' => ['accepted', 'cancelled'],
        'accepted' => ['assigned', 'cancelled'],
        'assigned' => ['assigned', 'accepted', 'picked_up', 'cancelled'],
        'picked_up' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public static function allowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(
        Order $order,
        OrderStatus $to,
        string $actorType,
        ?int $actorId,
        array $attributes = [],
        ?string $note = null,
        ?int $driverId = null,
    ): Order {
        $from = $order->status;

        if (! self::allowed($from, $to)) {
            throw new BusinessRuleException(__('admin.errors.illegal_transition', [
                'from' => $from->label(),
                'to' => $to->label(),
            ]));
        }

        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('status', $from->value)
            ->update(array_merge($attributes, ['status' => $to->value, 'updated_at' => now()]));

        if ($updated !== 1) {
            throw new BusinessRuleException(__('admin.errors.order_changed_concurrently'), 409);
        }

        OrderStatusHistory::create([
            'order_id' => $order->getKey(),
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'driver_id' => $driverId,
            'note' => $note,
        ]);

        return $order->refresh();
    }
}
