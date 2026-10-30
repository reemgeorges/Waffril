<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\OrderCancellation;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Services\Audit\AdminAudit;
use App\Services\Wallet\WalletLedger;
use App\Support\Auth\AdminPermissions;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — full or partial refunds, always credited to the
 * customer's wallet in the ORDER currency.
 *
 * Caps: a refund can never exceed what was actually collected and not
 * already reversed/refunded (collected − reversed − refunded); uncollected
 * cash is never refundable. Allowed on delivered orders, or on a cancelled
 * order for cash that the driver actually collected (linked to the
 * cancellation record). Idempotent on the idempotency key.
 */
class RefundService
{
    public function __construct(protected WalletLedger $ledger) {}

    public function refund(
        OrderPayment $payment,
        string $amount,
        string $reasonCode,
        ?string $note,
        Admin $admin,
        string $idempotencyKey,
        ?OrderCancellation $cancellation = null,
        bool $checkPermission = true,
    ): OrderRefund {
        if ($checkPermission && ! AdminPermissions::allows($admin, 'refunds.create')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }

        return DB::transaction(function () use ($payment, $amount, $reasonCode, $note, $admin, $idempotencyKey, $cancellation) {
            $existing = OrderRefund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $payment = OrderPayment::query()->lockForUpdate()->findOrFail($payment->getKey());
            $order = $payment->order;
            $value = Money::of($amount);

            if ($value->isLessThanOrEqualTo(0) || $value->getScale() > Money::STORAGE_SCALE) {
                throw new BusinessRuleException(__('admin.errors.amount_must_be_positive'));
            }

            $eligible = $order->status === OrderStatus::Delivered
                || ($order->status === OrderStatus::Cancelled && $cancellation !== null && $cancellation->order_id === $order->id);
            if (! $eligible) {
                throw new BusinessRuleException(__('admin.errors.refund_not_allowed_for_status'));
            }

            if ($value->isGreaterThan($payment->returnableAmount())) {
                throw new BusinessRuleException(__('admin.errors.refund_exceeds_collected', [
                    'max' => (string) $payment->returnableAmount(),
                ]));
            }

            $refund = OrderRefund::create([
                'order_id' => $order->id,
                'order_payment_id' => $payment->id,
                'order_cancellation_id' => $cancellation?->id,
                'currency_id' => $payment->currency_id,
                'amount' => Money::toStorage($value),
                'reason_code' => $reasonCode,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'created_by_admin_id' => $admin->id,
            ]);

            OrderPayment::query()->whereKey($payment->id)->update([
                'refunded_amount' => Money::toStorage(Money::of((string) $payment->refunded_amount)->plus($value)),
                'updated_at' => now(),
            ]);

            $this->ledger->creditRefund($refund);

            AdminAudit::log('order.refunded', $order, [
                'payment_id' => $payment->id,
                'amount' => Money::toStorage($value),
                'reason' => $reasonCode,
            ], $admin);

            return $refund;
        });
    }
}
