<?php

namespace App\Services\Driver;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Notifications\DriverDeliveryProblemReported;
use App\Services\Audit\AdminAudit;
use App\Services\Order\OrderAdminService;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * WAFFRLI driver API (2026-11-02) — the driver's side of the delivery
 * workflow. State changes are delegated to OrderAdminService
 * (markPickedUp / markDelivered → OrderStateMachine), so the transition
 * rules, row locks and status history live in ONE place.
 *
 * Added here, on top of those services:
 *  - ownership: an order that is not this driver's is "not found" (404),
 *    so order ids of other drivers cannot even be probed;
 *  - cash confirmation: the driver states the cash actually received; it
 *    must equal what is due, in the currency frozen at checkout, otherwise
 *    nothing is marked collected (the driver reports a problem instead);
 *  - duplicate completion → 409; optional proof-of-delivery photo on the
 *    private disk;
 *  - problem reports to the operations team (drivers never cancel orders);
 *  - last-position updates, only while a delivery is active.
 */
class DriverDeliveryService
{
    public const PHOTO_DISK = 'local';

    public function __construct(protected OrderAdminService $orders) {}

    /** The driver's own order (current or historical), else 404. */
    public function ownOrder(Driver $driver, int|string $orderId): Order
    {
        $order = Order::query()->whereKey($orderId)->where('driver_id', $driver->id)->first();
        if (! $order) {
            throw new BusinessRuleException(__('messages.driver.order_not_found'), 404);
        }

        return $order;
    }

    /** Cash still to collect on delivery (pending cash payments), in the order currency. */
    public static function cashDue(Order $order): BigDecimal
    {
        return OrderPayment::query()->where('order_id', $order->id)
            ->where('status', OrderPayment::STATUS_PENDING)
            ->whereHas('method', fn ($query) => $query->where('kind', 'cash'))
            ->get()
            ->reduce(fn (BigDecimal $carry, OrderPayment $p) => $carry->plus((string) $p->amount), BigDecimal::zero());
    }

    /** Already paid before delivery (wallet), never collected again as cash. */
    public static function prepaid(Order $order): BigDecimal
    {
        return OrderPayment::query()->where('order_id', $order->id)
            ->whereHas('method', fn ($query) => $query->where('kind', '!=', 'cash'))
            ->get()
            ->reduce(fn (BigDecimal $carry, OrderPayment $p) => $carry->plus((string) $p->collected_amount)->minus((string) $p->reversed_amount), BigDecimal::zero());
    }

    public function pickUp(Driver $driver, int|string $orderId): Order
    {
        $order = $this->ownOrder($driver, $orderId);

        return $this->orders->markPickedUp($order, $driver)->fresh();
    }

    /**
     * @param  string|null  $cashCollected  amount the driver received (required when cash is due)
     * @param  string|null  $currencyCode  optional echo of the collection currency, checked when given
     */
    public function deliver(Driver $driver, int|string $orderId, ?string $cashCollected, ?string $currencyCode, ?UploadedFile $photo): Order
    {
        $order = $this->ownOrder($driver, $orderId);
        if ($order->status === OrderStatus::Delivered) {
            throw new BusinessRuleException(__('messages.driver.already_delivered'), 409);
        }

        $currency = $order->currency;
        if ($currencyCode !== null && strtoupper($currencyCode) !== $currency->code) {
            throw new BusinessRuleException(__('messages.driver.cash_currency_mismatch', ['currency' => $currency->code]), 422);
        }

        $due = self::cashDue($order);
        $collected = BigDecimal::of($cashCollected !== null && $cashCollected !== '' ? $cashCollected : '0');
        if ($due->isZero() && ! $collected->isZero()) {
            throw new BusinessRuleException(__('messages.driver.cash_not_due'), 422);
        }
        if (! $collected->isEqualTo($due)) {
            throw new BusinessRuleException(__('messages.driver.cash_amount_mismatch', [
                'collected' => (string) $collected, 'due' => Money::roundTo($due, $currency->decimals), 'currency' => $currency->code,
            ]), 422);
        }

        $path = $photo ? $photo->storeAs('delivery-proofs/'.$order->id, Str::uuid().'.'.($photo->extension() ?: 'jpg'), self::PHOTO_DISK) : null;

        try {
            return DB::transaction(function () use ($order, $driver, $path) {
                // markDelivered locks the order, re-checks the assignment and
                // status, collects exactly the pending cash and checks that
                // payments cover the total — all atomically.
                $delivered = $this->orders->markDelivered($order, $driver);
                if ($path) {
                    Order::query()->whereKey($delivered->id)->update(['delivery_photo_path' => $path]);
                }

                return $delivered->fresh();
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk(self::PHOTO_DISK)->delete($path);
            }
            // A concurrent second "deliver" loses the race inside the lock: report it as a duplicate.
            if ($e instanceof BusinessRuleException && $order->fresh()->status === OrderStatus::Delivered) {
                throw new BusinessRuleException(__('messages.driver.already_delivered'), 409);
            }
            throw $e;
        }
    }

    public function reportProblem(Driver $driver, int|string $orderId, string $type, ?string $note): array
    {
        $order = $this->ownOrder($driver, $orderId);
        if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::PickedUp], true)) {
            throw new BusinessRuleException(__('messages.driver.problem_requires_active_order'), 409);
        }

        $recipients = Admin::query()->where('is_active', true)->get()
            ->filter(fn (Admin $admin) => AdminPermissions::allows($admin, 'orders.view'));
        Notification::send($recipients, new DriverDeliveryProblemReported($driver, $order, $type, $note));

        // Always kept in the audit trail (no admin actor: reported by a driver).
        AdminAudit::log('driver.problem_reported', $order, ['driver_id' => $driver->id, 'type' => $type, 'note' => $note]);

        return ['order_id' => $order->id, 'order_number' => $order->order_number, 'type' => $type, 'notified_admins' => $recipients->count()];
    }

    /**
     * Stores the driver's LAST position (drivers.last_lat/last_lng/last_location_at;
     * no history). Accepted only while the driver has an assigned or picked-up
     * delivery; the Filament driver map reads exactly these columns.
     */
    public function updateLocation(Driver $driver, float $lat, float $lng): Driver
    {
        if (! $driver->activeOrders()->exists()) {
            throw new BusinessRuleException(__('messages.driver.no_active_delivery'), 409);
        }

        Driver::query()->whereKey($driver->id)->update([
            'last_lat' => round($lat, 7),
            'last_lng' => round($lng, 7),
            'last_location_at' => now(),
            'updated_at' => now(),
        ]);

        return $driver->fresh();
    }
}
