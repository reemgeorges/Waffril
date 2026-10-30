<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\BranchProduct;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationRequest;
use App\Models\OrderPayment;
use App\Models\PlusCycle;
use App\Notifications\DriverOrderUpdate;
use App\Services\Audit\AdminAudit;
use App\Services\Catalog\BranchHoursService;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Plus\PlusService;
use App\Services\Wallet\WalletLedger;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — admin order workflow (also reusable by future
 * APIs). Every method checks its own permission, runs in one transaction
 * and goes through OrderStateMachine.
 *
 * Cancellation financial rules:
 *  - before pickup: every wallet payment is reversed in full, pending cash
 *    is cancelled, a used PLUS free delivery is restored;
 *  - after pickup (needs `orders.cancel_after_pickup`): the admin records
 *    the driver's handling and the cash actually collected; the wallet
 *    reversal is chosen between 0 and the reversible amount; collected
 *    cash can only go back to the customer as a capped wallet refund; the
 *    free-delivery restore is the admin's choice.
 *  - one cancellation per order (unique), idempotent on its key; a wallet
 *    payment can be reversed only once (ledger unique key).
 */
class OrderAdminService
{
    public function __construct(
        protected OrderStateMachine $machine,
        protected WalletLedger $ledger,
        protected RefundService $refunds,
        protected BranchHoursService $hours,
        protected DriverRecommendationService $recommendations,
        protected PlusService $plus,
    ) {}

    protected function authorize(Admin $admin, string $permission): void
    {
        if (! AdminPermissions::allows($admin, $permission)) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }

    /** Scheduled orders become acceptable this many minutes before their time. */
    public function acceptanceOpensAt(Order $order): ?Carbon
    {
        if (! $order->isScheduled()) {
            return null;
        }

        return $order->scheduled_for->copy()->subMinutes((int) PlatformSettings::get('orders.scheduled_acceptance_window_minutes'));
    }

    public function accept(Order $order, Admin $admin): Order
    {
        $this->authorize($admin, 'orders.accept');

        return DB::transaction(function () use ($order, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->status !== OrderStatus::Pending) {
                throw new BusinessRuleException(__('admin.errors.order_not_pending'));
            }

            $opensAt = $this->acceptanceOpensAt($order);
            if ($opensAt && now()->lt($opensAt)) {
                throw new BusinessRuleException(__('admin.errors.scheduled_not_yet_acceptable', ['at' => $opensAt->toDateTimeString()]));
            }

            $branch = $order->branch;
            $at = $order->isScheduled() ? $order->scheduled_for : now();
            if (! $branch || ! $this->hours->isOpenAt($branch, $at) || (! $order->isScheduled() && ! $branch->is_accepting_orders)) {
                throw new BusinessRuleException(__('admin.errors.branch_closed'));
            }

            foreach ($order->items()->with('options')->get() as $item) {
                $branchProduct = $item->branch_product_id ? BranchProduct::find($item->branch_product_id) : null;
                if (! $branchProduct || ! $branchProduct->is_available || ! $item->product || ! $item->product->is_active || $item->product->trashed()) {
                    throw new BusinessRuleException(__('admin.errors.item_unavailable_cancel_instead'));
                }
                foreach ($item->options as $option) {
                    $available = $option->product_option_id && $branchProduct->options()
                        ->where('product_option_id', $option->product_option_id)->where('is_available', true)->exists();
                    if (! $available) {
                        throw new BusinessRuleException(__('admin.errors.item_unavailable_cancel_instead'));
                    }
                }
            }

            $order = $this->machine->transition($order, OrderStatus::Accepted, 'admin', $admin->id, [
                'accepted_at' => now(),
                'accepted_by_admin_id' => $admin->id,
                'urgent_at' => null,
            ]);

            AdminAudit::log('order.accepted', $order, [], $admin);

            return $order;
        });
    }

    public function assignDriver(Order $order, Driver $driver, Admin $admin): Order
    {
        $this->authorize($admin, 'orders.assign');

        return DB::transaction(function () use ($order, $driver, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($order->status, [OrderStatus::Accepted, OrderStatus::Assigned], true)) {
                throw new BusinessRuleException(__('admin.errors.order_not_assignable'));
            }

            if (! $this->recommendations->isEligible($driver, $order)) {
                throw new BusinessRuleException(__('admin.errors.driver_not_eligible'));
            }

            $previousDriverId = $order->driver_id;
            $order = $this->machine->transition($order, OrderStatus::Assigned, 'admin', $admin->id, [
                'driver_id' => $driver->id,
                'assigned_at' => now(),
                'assigned_by_admin_id' => $admin->id,
            ], null, $driver->id);

            AdminAudit::log('order.driver_assigned', $order, ['driver_id' => $driver->id], $admin);

            // WAFFRLI driver API (2026-11-02): stored driver notifications.
            $driver->notify(new DriverOrderUpdate('assigned', $order));
            if ($previousDriverId && $previousDriverId !== $driver->id) {
                Driver::find($previousDriverId)?->notify(new DriverOrderUpdate('unassigned', $order));
            }

            return $order;
        });
    }

    public function unassignDriver(Order $order, Admin $admin): Order
    {
        $this->authorize($admin, 'orders.assign');

        return DB::transaction(function () use ($order, $admin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $previous = $order->driver_id;

            $order = $this->machine->transition($order, OrderStatus::Accepted, 'admin', $admin->id, [
                'driver_id' => null,
                'assigned_at' => null,
                'assigned_by_admin_id' => null,
            ], 'driver unassigned', $previous);

            AdminAudit::log('order.driver_unassigned', $order, ['driver_id' => $previous], $admin);
            if ($previous) {
                Driver::find($previous)?->notify(new DriverOrderUpdate('unassigned', $order));
            }

            return $order;
        });
    }

    /**
     * @param array{reason_code: string, note?: ?string, driver_handling?: string,
     *     cash_collected_amount?: string|int|float|null, wallet_reversal_amount?: string|int|float|null,
     *     refund_collected_cash_amount?: string|int|float|null, restore_free_delivery?: bool,
     *     idempotency_key?: ?string} $data
     */
    public function cancelByAdmin(Order $order, Admin $admin, array $data, ?OrderCancellationRequest $request = null): OrderCancellation
    {
        $this->authorize($admin, 'orders.cancel');
        if ($order->fresh()->status === OrderStatus::PickedUp) {
            $this->authorize($admin, 'orders.cancel_after_pickup');
        }

        return $this->cancel($order, 'admin', $admin, $data, $request);
    }

    /** Customer self-cancellation: only while the order is still pending. */
    public function cancelByCustomer(Order $order, ?string $note = null, ?string $idempotencyKey = null): OrderCancellation
    {
        if ($order->fresh()->status !== OrderStatus::Pending) {
            throw new BusinessRuleException(__('admin.errors.customer_cancel_requires_approval'));
        }

        return $this->cancel($order, 'customer', null, [
            'reason_code' => 'customer_request',
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    protected function cancel(Order $order, string $by, ?Admin $admin, array $data, ?OrderCancellationRequest $request = null): OrderCancellation
    {
        $key = $data['idempotency_key'] ?? null ?: 'order:'.$order->getKey().':cancel:'.Str::uuid();

        return DB::transaction(function () use ($order, $by, $admin, $data, $request, $key) {
            $existing = OrderCancellation::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $order->status;

            if (! in_array($from, [OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::Assigned, OrderStatus::PickedUp], true)) {
                throw new BusinessRuleException(__('admin.errors.order_not_cancellable'));
            }

            if (! in_array($data['reason_code'] ?? null, OrderCancellation::REASONS, true)) {
                throw new BusinessRuleException(__('admin.errors.invalid_reason'));
            }

            $afterPickup = $from === OrderStatus::PickedUp;
            $handling = $afterPickup ? ($data['driver_handling'] ?? null) : 'not_applicable';
            if ($afterPickup && ! in_array($handling, array_diff(OrderCancellation::DRIVER_HANDLING, ['not_applicable']), true)) {
                throw new BusinessRuleException(__('admin.errors.driver_handling_required'));
            }

            $this->machine->transition($order, OrderStatus::Cancelled, $by, $admin?->id, [], $data['note'] ?? null, $order->driver_id);

            $walletReversed = Money::of(0);
            $cashCollected = Money::of(0);
            $collectedCashPayment = null;

            foreach (OrderPayment::query()->where('order_id', $order->id)->lockForUpdate()->get() as $payment) {
                $kind = $payment->method->kind;

                if ($kind === 'wallet' && $payment->status === OrderPayment::STATUS_PAID) {
                    $reversible = $payment->returnableAmount();
                    $amount = $afterPickup && array_key_exists('wallet_reversal_amount', $data) && $data['wallet_reversal_amount'] !== null
                        ? Money::of((string) $data['wallet_reversal_amount'])
                        : $reversible;

                    if ($amount->isLessThan(0) || $amount->isGreaterThan($reversible)) {
                        throw new BusinessRuleException(__('admin.errors.reversal_exceeds_reversible', ['max' => (string) $reversible]));
                    }

                    if ($amount->isGreaterThan(0)) {
                        $this->ledger->creditReversal($payment, $amount);
                        $newReversed = Money::of((string) $payment->reversed_amount)->plus($amount);
                        OrderPayment::query()->whereKey($payment->id)->update([
                            'reversed_amount' => Money::toStorage($newReversed),
                            'status' => $newReversed->isEqualTo((string) $payment->collected_amount) ? OrderPayment::STATUS_REVERSED : OrderPayment::STATUS_PAID,
                            'updated_at' => now(),
                        ]);
                        $walletReversed = $walletReversed->plus($amount);
                    }
                } elseif ($kind === 'cash' && $payment->status === OrderPayment::STATUS_PENDING) {
                    $collected = $afterPickup ? Money::of((string) ($data['cash_collected_amount'] ?? 0)) : Money::of(0);

                    if ($collected->isLessThan(0) || $collected->isGreaterThan((string) $payment->amount)) {
                        throw new BusinessRuleException(__('admin.errors.cash_collected_exceeds_amount'));
                    }

                    if ($collected->isGreaterThan(0)) {
                        OrderPayment::query()->whereKey($payment->id)->update([
                            'collected_amount' => Money::toStorage($collected),
                            'collected_by_driver_id' => $order->driver_id,
                            'status' => OrderPayment::STATUS_PAID,
                            'paid_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $cashCollected = $collected;
                        $collectedCashPayment = $payment;
                    } else {
                        OrderPayment::query()->whereKey($payment->id)->update(['status' => OrderPayment::STATUS_CANCELLED, 'updated_at' => now()]);
                    }
                } elseif ($payment->status === OrderPayment::STATUS_PENDING) {
                    OrderPayment::query()->whereKey($payment->id)->update(['status' => OrderPayment::STATUS_CANCELLED, 'updated_at' => now()]);
                }
            }

            $restore = $order->free_delivery_applied && $order->plus_cycle_id
                && ($afterPickup ? (bool) ($data['restore_free_delivery'] ?? false) : true);
            if ($restore) {
                $restore = $this->plus->restoreFreeDelivery(PlusCycle::findOrFail($order->plus_cycle_id));
            }

            $cancellation = OrderCancellation::create([
                'order_id' => $order->id,
                'from_status' => $from->value,
                'cancelled_by' => $by,
                'admin_id' => $admin?->id,
                'cancellation_request_id' => $request?->id,
                'reason_code' => $data['reason_code'],
                'note' => $data['note'] ?? null,
                'driver_id' => $order->driver_id,
                'driver_handling' => $handling,
                'cash_collected_amount' => Money::toStorage($cashCollected),
                'wallet_reversed_amount' => Money::toStorage($walletReversed),
                'free_delivery_restored' => (bool) $restore,
                'idempotency_key' => $key,
            ]);

            $refundCash = Money::of((string) ($data['refund_collected_cash_amount'] ?? 0));
            if ($refundCash->isGreaterThan(0)) {
                if (! $collectedCashPayment || ! $admin) {
                    throw new BusinessRuleException(__('admin.errors.no_collected_cash_to_refund'));
                }
                $this->refunds->refund($collectedCashPayment, (string) $refundCash, 'order_cancelled', $data['note'] ?? null, $admin, $key.':cash-refund', $cancellation);
            }

            if ($request) {
                $request->forceFill([
                    'status' => OrderCancellationRequest::STATUS_APPROVED,
                    'resolved_by_admin_id' => $admin?->id,
                    'resolved_at' => now(),
                ])->save();
            }

            if ($order->driver_id) {
                Driver::find($order->driver_id)?->notify(new DriverOrderUpdate('cancelled', $order));
            }

            if ($admin) {
                AdminAudit::log('order.cancelled', $order, [
                    'from' => $from->value,
                    'reason' => $data['reason_code'],
                    'wallet_reversed' => Money::toStorage($walletReversed),
                    'cash_collected' => Money::toStorage($cashCollected),
                ], $admin);
            }

            return $cancellation;
        });
    }

    public function rejectCancellationRequest(OrderCancellationRequest $request, Admin $admin, ?string $note): OrderCancellationRequest
    {
        $this->authorize($admin, 'orders.resolve_cancellation_requests');

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = OrderCancellationRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status !== OrderCancellationRequest::STATUS_PENDING) {
                throw new BusinessRuleException(__('admin.errors.request_not_pending'));
            }

            $request->forceFill([
                'status' => OrderCancellationRequest::STATUS_REJECTED,
                'resolved_by_admin_id' => $admin->id,
                'resolved_at' => now(),
                'admin_note' => $note,
            ])->save();

            AdminAudit::log('order.cancellation_request_rejected', $request->order, ['request_id' => $request->id], $admin);

            return $request;
        });
    }

    public function approveCancellationRequest(OrderCancellationRequest $request, Admin $admin, array $data): OrderCancellation
    {
        $this->authorize($admin, 'orders.resolve_cancellation_requests');

        if ($request->fresh()->status !== OrderCancellationRequest::STATUS_PENDING) {
            throw new BusinessRuleException(__('admin.errors.request_not_pending'));
        }

        return $this->cancelByAdmin($request->order, $admin, array_merge(['reason_code' => 'customer_request'], $data), $request);
    }

    /** Scheduler: late scheduled orders stay pending and are flagged urgent (never auto-cancelled). */
    public function flagLateScheduledOrders(): int
    {
        return Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('type', Order::TYPE_SCHEDULED)
            ->whereNull('urgent_at')
            ->where('scheduled_for', '<', now())
            ->update(['urgent_at' => now()]);
    }

    /* ---- Driver-side transitions (future driver API; used by tests). ---- */

    public function markPickedUp(Order $order, Driver $driver): Order
    {
        return DB::transaction(function () use ($order, $driver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($order->driver_id !== $driver->id) {
                throw new BusinessRuleException(__('admin.errors.not_assigned_driver'), 403);
            }

            return $this->machine->transition($order, OrderStatus::PickedUp, 'driver', $driver->id, ['picked_up_at' => now()], null, $driver->id);
        });
    }

    public function markDelivered(Order $order, Driver $driver): Order
    {
        return DB::transaction(function () use ($order, $driver) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($order->driver_id !== $driver->id) {
                throw new BusinessRuleException(__('admin.errors.not_assigned_driver'), 403);
            }

            foreach (OrderPayment::query()->where('order_id', $order->id)->lockForUpdate()->get() as $payment) {
                if ($payment->method->kind === 'cash' && $payment->status === OrderPayment::STATUS_PENDING) {
                    OrderPayment::query()->whereKey($payment->id)->update([
                        'collected_amount' => (string) $payment->amount,
                        'collected_by_driver_id' => $driver->id,
                        'status' => OrderPayment::STATUS_PAID,
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $collected = Money::of((string) (OrderPayment::where('order_id', $order->id)->sum('collected_amount') ?: '0'));
            if (! $collected->isEqualTo((string) $order->total)) {
                throw new BusinessRuleException(__('admin.errors.payments_do_not_cover_total'));
            }

            return $this->machine->transition($order, OrderStatus::Delivered, 'driver', $driver->id, ['delivered_at' => now()], null, $driver->id);
        });
    }
}
