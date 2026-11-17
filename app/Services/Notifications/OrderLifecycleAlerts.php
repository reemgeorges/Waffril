<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * WAFFRLI (2026-11-17) — which order lifecycle events alert admins in the
 * Filament bell (stored, unread until handled, with a live toast).
 *
 * Business rules:
 *  - The driver accepted an offer (order → assigned by the driver):
 *    order.driver_accepted, to admins who can assign drivers.
 *  - The driver declined an offer, or an offer expired unanswered:
 *    order.offer_rejected / order.offer_expired, to the same admins (the
 *    dispatcher moves on to the next driver; "no driver available" keeps
 *    its own alert from DeliveryDispatcher).
 *  - The driver picked the order up / delivered it: order.picked_up /
 *    order.delivered, to every admin who follows orders.
 *  - The customer cancelled a pending order: order.cancelled_by_customer.
 *  - Problems reported by drivers keep their existing alert.
 *  - Changes an ADMIN makes (accept, assign, unassign, cancel, refund) do not
 *    alert: the admin who acted already knows and every open screen refreshes
 *    live over Reverb. GPS pings and dispatch refresh events never alert.
 *
 * Every alert is sent after the database commit and at most once per
 * underlying record (status-history row or offer outcome).
 */
final class OrderLifecycleAlerts
{
    /** Called by OrderStateMachine for every committed-to-be transition. */
    public static function transition(Order $order, OrderStatus $from, OrderStatus $to, string $actorType, OrderStatusHistory $history): void
    {
        $type = match (true) {
            $to === OrderStatus::Assigned && $actorType === 'driver' => 'order.driver_accepted',
            $to === OrderStatus::PickedUp && $actorType === 'driver' => 'order.picked_up',
            $to === OrderStatus::Delivered && $actorType === 'driver' => 'order.delivered',
            $to === OrderStatus::Cancelled && $actorType === 'customer' => 'order.cancelled_by_customer',
            default => null,
        };
        if ($type === null) {
            return;
        }

        AdminAlerts::send($type, (int) $order->id, (string) $order->order_number,
            ['driver' => self::driverName($order->driver_id ?? $history->driver_id)], 'history:'.$history->id);
    }

    /** Called when an offer is finalized (rejected, expired, cancelled, accepted). */
    public static function offer(DeliveryOffer $offer, DeliveryOfferStatus $status): void
    {
        $type = match ($status) {
            DeliveryOfferStatus::Rejected => 'order.offer_rejected',
            DeliveryOfferStatus::Expired => 'order.offer_expired',
            default => null,   // accepted: the transition alerts; cancelled: an admin or the order decided
        };
        if ($type === null) {
            return;
        }

        $number = (string) Order::query()->whereKey($offer->order_id)->value('order_number');
        AdminAlerts::send($type, (int) $offer->order_id, $number,
            ['driver' => self::driverName($offer->driver_id)], 'offer:'.$offer->id.':'.$status->value);
    }

    private static function driverName(?int $driverId): string
    {
        return ($driverId ? Driver::query()->whereKey($driverId)->value('name') : null) ?? '—';
    }
}
