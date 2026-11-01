<?php

namespace App\Support\Realtime;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\Auth\AdminPermissions;

/**
 * WAFFRLI (2026-11-03) — who may LISTEN on which private channel of the one
 * shared Reverb server. Used by routes/channels.php (subscription time) and
 * by the publishers (send time), so access is checked twice: a client that
 * subscribed while it was allowed receives nothing sensitive (GPS) after a
 * reassignment, completion, cancellation or suspension.
 *
 * Channel names are declared WITHOUT the `private-` prefix (Laravel adds it
 * through PrivateChannel; raw Pusher clients subscribe to `private-order.5`).
 */
class ChannelAccess
{
    /** Order statuses during which the customer's order channel is open. */
    public const CUSTOMER_ORDER_STATUSES = [OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::Assigned, OrderStatus::PickedUp];

    /** Order statuses during which the assigned driver (and GPS) is live. */
    public const DELIVERY_STATUSES = [OrderStatus::Assigned, OrderStatus::PickedUp];

    public static function customerIsActive(mixed $user): bool
    {
        return $user instanceof User && ! $user->trashed() && $user->anonymized_at === null
            && $user->is_active && ! $user->isSuspended();
    }

    public static function driverIsActive(mixed $driver): bool
    {
        return $driver instanceof Driver && $driver->is_active && ! $driver->trashed();
    }

    public static function user(mixed $user, int|string $id): bool
    {
        return self::customerIsActive($user) && ctype_digit((string) $id) && (int) $user->id === (int) $id;
    }

    public static function driver(mixed $driver, int|string $id): bool
    {
        return self::driverIsActive($driver) && ctype_digit((string) $id) && (int) $driver->id === (int) $id;
    }

    /** The order's customer while the order is open, or its CURRENT driver while delivering. */
    public static function order(mixed $actor, int|string $orderId): bool
    {
        if (! ctype_digit((string) $orderId)) {
            return false;
        }
        $order = Order::query()->select(['id', 'user_id', 'driver_id', 'status'])->find((int) $orderId);
        if (! $order) {
            return false;
        }

        if ($actor instanceof User) {
            return self::customerIsActive($actor) && (int) $order->user_id === (int) $actor->id
                && in_array($order->status, self::CUSTOMER_ORDER_STATUSES, true);
        }
        if ($actor instanceof Driver) {
            return self::driverIsActive($actor) && (int) $order->driver_id === (int) $actor->id
                && in_array($order->status, self::DELIVERY_STATUSES, true);
        }

        return false;
    }

    public static function adminOrders(mixed $admin): bool
    {
        return AdminPermissions::allows($admin instanceof Admin ? $admin : null, 'orders.view');
    }

    public static function adminDriversMap(mixed $admin): bool
    {
        return AdminPermissions::allows($admin instanceof Admin ? $admin : null, 'drivers.track');
    }

    public static function admin(mixed $admin, int|string $id): bool
    {
        return $admin instanceof Admin && $admin->is_active && ! $admin->trashed() && ctype_digit((string) $id) && (int) $admin->id === (int) $id;
    }

    /** Send-time check before GPS goes to an order channel. */
    public static function orderIsTrackable(Order $order, Driver $driver): bool
    {
        return (int) $order->driver_id === (int) $driver->id && in_array($order->status, self::DELIVERY_STATUSES, true)
            && self::driverIsActive($driver);
    }
}
