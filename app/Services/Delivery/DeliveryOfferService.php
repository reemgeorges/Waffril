<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Order\OrderStateMachine;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — core domain of delivery offers.
 *
 * An ACCEPTED order is offered to one driver at a time. The order stays
 * `accepted` while offers are out; it becomes `assigned` only when the
 * offered driver accepts in time, through the existing OrderStateMachine
 * (so customer notifications and realtime are unchanged).
 *
 * Concurrency: every mutation runs in a transaction that locks the ORDER
 * row first, then the offer, then the driver (one lock order everywhere,
 * no deadlocks). The database backs the invariants with unique markers:
 * one pending offer per order, one per driver, one offer per driver per
 * order per round. Time is server time only.
 *
 * B1 has no HTTP routes, jobs, notifications or admin UI: nothing calls
 * this service in production yet, and offers cannot be created while
 * config('dispatch.offers_enabled') is off (the default).
 */
class DeliveryOfferService
{
    public function __construct(
        protected OrderStateMachine $machine,
        protected DriverRecommendationService $recommendations,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('dispatch.offers_enabled', false);
    }

    public static function timeoutSeconds(): int
    {
        [$min, $max] = config('dispatch.timeout_bounds_seconds', [30, 300]);

        return max($min, min($max, (int) PlatformSettings::get('dispatch.offer_timeout_seconds')));
    }

    public static function maxAttemptsPerRound(): int
    {
        [$min, $max] = config('dispatch.max_attempts_bounds', [1, 50]);

        return max($min, min($max, (int) PlatformSettings::get('dispatch.max_attempts_per_round')));
    }

    /** The admin picks the first driver: starts a NEW dispatch round. */
    public function offerByAdmin(Order $order, Driver $driver, Admin $admin): DeliveryOffer
    {
        if (! AdminPermissions::allows($admin, 'orders.assign')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }

        return $this->createOffer($order, $driver, DeliveryOffer::SOURCE_ADMIN, $admin, true, []);
    }

    /** The system offers the next attempt of the CURRENT round (driver chosen by B3). */
    public function offerAutomatically(Order $order, Driver $driver, array $selectionMeta = []): DeliveryOffer
    {
        return $this->createOffer($order, $driver, DeliveryOffer::SOURCE_AUTO, null, false, $selectionMeta);
    }

    protected function createOffer(Order $order, Driver $driver, string $source, ?Admin $admin, bool $newRound, array $meta): DeliveryOffer
    {
        if (! self::enabled()) {
            throw new BusinessRuleException(__('admin.errors.dispatch_disabled'), 409);
        }

        try {
            return DB::transaction(function () use ($order, $driver, $source, $admin, $newRound, $meta) {
                $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                if ($order->status !== OrderStatus::Accepted) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_order_not_accepted'), 422);
                }
                if (DeliveryOffer::query()->where('pending_order_marker', $order->id)->exists()) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_offer_pending'), 409);
                }
                if (! $newRound && $order->dispatch_round < 1) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_no_round'), 409);
                }

                $round = $newRound ? $order->dispatch_round + 1 : $order->dispatch_round;
                $attempt = ($newRound ? 0 : $order->dispatch_attempts) + 1;
                if ($attempt > self::maxAttemptsPerRound()) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_attempts_exhausted'), 422);
                }

                $driver = Driver::query()->lockForUpdate()->findOrFail($driver->getKey());
                if (! $this->recommendations->isEligible($driver, $order)) {
                    throw new BusinessRuleException(__('admin.errors.driver_not_eligible'), 422);
                }
                if (DeliveryOffer::query()->where('pending_driver_marker', $driver->id)->exists()) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_driver_has_offer'), 409);
                }
                if (DeliveryOffer::query()->where('order_id', $order->id)->where('round', $round)->where('driver_id', $driver->id)->exists()) {
                    throw new BusinessRuleException(__('admin.errors.dispatch_driver_already_offered'), 422);
                }

                $now = now();
                $offer = DeliveryOffer::create([
                    'order_id' => $order->id,
                    'driver_id' => $driver->id,
                    'round' => $round,
                    'attempt' => $attempt,
                    'status' => DeliveryOfferStatus::Pending,
                    'source' => $source,
                    'offered_by_admin_id' => $admin?->id,
                    'offered_at' => $now,
                    'expires_at' => $now->copy()->addSeconds(self::timeoutSeconds()),
                    'selection_meta' => $meta ?: null,
                ]);

                Order::query()->whereKey($order->id)->update([
                    'dispatch_state' => Order::DISPATCH_OFFERING,
                    'dispatch_round' => $round,
                    'dispatch_attempts' => $attempt,
                    'no_driver_since' => null,
                    'no_driver_alerted_at' => null,
                    'updated_at' => $now,
                ]);

                return $offer;
            });
        } catch (QueryException $e) {
            // A unique marker lost a race with a concurrent offer.
            if ($this->isUniqueViolation($e)) {
                throw new BusinessRuleException(__('admin.errors.dispatch_offer_pending'), 409);
            }
            throw $e;
        }
    }

    /**
     * The offered driver accepts. Idempotent: accepting an offer this
     * driver already accepted returns the assigned order again.
     */
    public function accept(DeliveryOffer $offer, Driver $driver): Order
    {
        $outcome = DB::transaction(function () use ($offer, $driver) {
            $order = Order::query()->lockForUpdate()->findOrFail($offer->order_id);
            $offer = DeliveryOffer::query()->lockForUpdate()->findOrFail($offer->getKey());
            $this->assertOwner($offer, $driver);

            if ($offer->status === DeliveryOfferStatus::Accepted
                && (int) $order->assigned_via_offer_id === $offer->id
                && (int) $order->driver_id === $driver->id) {
                return ['order' => $order];
            }
            if ($offer->isOverdue()) {
                $this->finalize($offer, DeliveryOfferStatus::Expired);

                return ['error' => 'expired'];
            }
            if ($offer->status !== DeliveryOfferStatus::Pending) {
                return ['error' => 'unavailable'];
            }
            if ($order->status !== OrderStatus::Accepted) {
                $this->finalize($offer, DeliveryOfferStatus::Cancelled, ['cancel_reason' => DeliveryOffer::CANCEL_ORDER_UNASSIGNABLE]);

                return ['error' => 'unavailable'];
            }

            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->getKey());
            if (! $this->recommendations->isEligible($driver, $order)) {
                $this->finalize($offer, DeliveryOfferStatus::Cancelled, ['cancel_reason' => DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE]);

                return ['error' => 'driver_unavailable'];
            }

            // Mark the offer first, so the state machine finds no pending
            // offer to cancel when the order leaves `accepted`.
            $this->finalize($offer, DeliveryOfferStatus::Accepted);
            $roundAdminId = DeliveryOffer::query()->where('order_id', $order->id)->where('round', $offer->round)
                ->whereNotNull('offered_by_admin_id')->orderBy('attempt')->value('offered_by_admin_id');

            $order = $this->machine->transition($order, OrderStatus::Assigned, 'driver', $driver->id, [
                'driver_id' => $driver->id,
                'assigned_at' => now(),
                'assigned_by_admin_id' => $roundAdminId,
                'assigned_via_offer_id' => $offer->id,
            ], 'delivery offer accepted', $driver->id);

            return ['order' => $order];
        });

        return match ($outcome['error'] ?? null) {
            null => $outcome['order'],
            'expired' => throw new BusinessRuleException(__('messages.driver.offer_expired'), 409),
            'driver_unavailable' => throw new BusinessRuleException(__('messages.driver.offer_driver_unavailable'), 409),
            default => throw new BusinessRuleException(__('messages.driver.offer_unavailable'), 409),
        };
    }

    /** The offered driver declines. Idempotent for an already rejected offer. */
    public function reject(DeliveryOffer $offer, Driver $driver, string $reason, ?string $note = null): DeliveryOffer
    {
        if (! in_array($reason, DeliveryOffer::REJECT_REASONS, true)) {
            throw new BusinessRuleException(__('messages.driver.offer_reject_reason_invalid'), 422);
        }

        $outcome = DB::transaction(function () use ($offer, $driver, $reason, $note) {
            Order::query()->lockForUpdate()->findOrFail($offer->order_id);
            $offer = DeliveryOffer::query()->lockForUpdate()->findOrFail($offer->getKey());
            $this->assertOwner($offer, $driver);

            if ($offer->status === DeliveryOfferStatus::Rejected) {
                return ['offer' => $offer];
            }
            if ($offer->isOverdue()) {
                $this->finalize($offer, DeliveryOfferStatus::Expired);

                return ['error' => 'expired'];
            }
            if ($offer->status !== DeliveryOfferStatus::Pending) {
                return ['error' => 'unavailable'];
            }

            return ['offer' => $this->finalize($offer, DeliveryOfferStatus::Rejected, [
                'reject_reason' => $reason,
                'reject_note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ])];
        });

        return match ($outcome['error'] ?? null) {
            null => $outcome['offer'],
            'expired' => throw new BusinessRuleException(__('messages.driver.offer_expired'), 409),
            default => throw new BusinessRuleException(__('messages.driver.offer_unavailable'), 409),
        };
    }

    /** Expires a pending offer past its deadline. Idempotent: false when there was nothing to do. */
    public function expire(DeliveryOffer $offer): bool
    {
        return DB::transaction(function () use ($offer) {
            Order::query()->lockForUpdate()->findOrFail($offer->order_id);
            $offer = DeliveryOffer::query()->lockForUpdate()->findOrFail($offer->getKey());
            if (! $offer->isOverdue()) {
                return false;
            }
            $this->finalize($offer, DeliveryOfferStatus::Expired);

            return true;
        });
    }

    /**
     * Withdraws the order's pending offer (admin override or restart,
     * order cancelled). Clears the dispatch state unless the admin is
     * restarting (a new round follows). Null when nothing was pending.
     */
    public function cancelPendingForOrder(Order $order, string $reason): ?DeliveryOffer
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $offer = DeliveryOffer::query()->lockForUpdate()->where('pending_order_marker', $order->id)->first();
            $offer = $offer ? $this->finalize($offer, DeliveryOfferStatus::Cancelled, ['cancel_reason' => $reason]) : null;
            if ($reason !== DeliveryOffer::CANCEL_ADMIN_RESTART && $order->dispatch_state !== null) {
                Order::query()->whereKey($order->id)->update(self::clearedDispatch());
            }

            return $offer;
        });
    }

    /** Withdraws the driver's pending offer (went offline, deactivated). */
    public function cancelPendingForDriver(Driver $driver, string $reason = DeliveryOffer::CANCEL_DRIVER_UNAVAILABLE): ?DeliveryOffer
    {
        $orderId = DeliveryOffer::query()->where('pending_driver_marker', $driver->id)->value('order_id');
        if ($orderId === null) {
            return null;
        }

        return DB::transaction(function () use ($driver, $orderId, $reason) {
            Order::query()->lockForUpdate()->findOrFail($orderId);
            $offer = DeliveryOffer::query()->lockForUpdate()->where('pending_driver_marker', $driver->id)->first();

            return $offer ? $this->finalize($offer, DeliveryOfferStatus::Cancelled, ['cancel_reason' => $reason]) : null;
        });
    }

    /**
     * Moves an accepted order without a pending offer into the admin's
     * pending-assignment queue. Returns true only when the state CHANGED,
     * so callers alert admins once per condition, not on every retry.
     */
    public function markNoDriver(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($order->status !== OrderStatus::Accepted
                || $order->dispatch_state === Order::DISPATCH_NO_DRIVER
                || DeliveryOffer::query()->where('pending_order_marker', $order->id)->exists()) {
                return false;
            }
            Order::query()->whereKey($order->id)->update([
                'dispatch_state' => Order::DISPATCH_NO_DRIVER,
                'no_driver_since' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Called by OrderStateMachine inside its transaction whenever an order
     * leaves `accepted`: withdraws a pending offer and returns the
     * attributes that clear the dispatch columns. A no-op (no queries
     * beyond one indexed lookup) for orders that never had a dispatch.
     *
     * @return array<string, mixed>
     */
    public static function onLeavingAccepted(Order $order, OrderStatus $to): array
    {
        $pending = DeliveryOffer::query()->lockForUpdate()->where('pending_order_marker', $order->getKey())->first();
        if ($pending) {
            $pending->forceFill([
                'status' => DeliveryOfferStatus::Cancelled,
                'responded_at' => now(),
                'cancel_reason' => $to === OrderStatus::Cancelled ? DeliveryOffer::CANCEL_ORDER_CANCELLED : DeliveryOffer::CANCEL_ADMIN_OVERRIDE,
            ])->save();
        }

        return $order->dispatch_state !== null || $pending ? self::clearedDispatch() : [];
    }

    /** @return array<string, mixed> */
    protected static function clearedDispatch(): array
    {
        return ['dispatch_state' => null, 'no_driver_since' => null, 'no_driver_alerted_at' => null];
    }

    protected function assertOwner(DeliveryOffer $offer, Driver $driver): void
    {
        // Another driver's offer does not exist for this driver.
        if ((int) $offer->driver_id !== (int) $driver->getKey()) {
            throw new BusinessRuleException(__('messages.driver.offer_not_found'), 404);
        }
    }

    protected function finalize(DeliveryOffer $offer, DeliveryOfferStatus $status, array $attributes = []): DeliveryOffer
    {
        $offer->forceFill(['status' => $status, 'responded_at' => now()] + $attributes)->save();

        return $offer;
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($code, [1062, 19, 2067], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
