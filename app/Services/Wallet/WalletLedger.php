<?php

namespace App\Services\Wallet;

use App\Exceptions\BusinessRuleException;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — the ONLY code allowed to change a wallet
 * balance. Every change is one append-only ledger row, written in the
 * same transaction that row-locks the wallet. Supported movements:
 *
 *   order_payment (debit) · order_payment_reversal (credit) · order_refund (credit)
 *
 * There is deliberately no top-up, withdrawal, conversion or manual
 * credit/debit method. Each movement is idempotent on its key: replaying
 * a key returns the original transaction instead of moving money twice.
 */
class WalletLedger
{
    /** Debit the customer's wallet for a wallet payment (at order submission). */
    public function debitForPayment(OrderPayment $payment): WalletTransaction
    {
        $order = $payment->order;

        return $this->post(
            $order->user_id,
            $payment->currency_id,
            WalletTransaction::TYPE_ORDER_PAYMENT,
            Money::of((string) $payment->amount),
            "payment:{$payment->id}:debit",
            ['order_id' => $order->id, 'order_payment_id' => $payment->id],
        );
    }

    /** Credit back (part of) a wallet payment when its order is cancelled. Exactly once per payment. */
    public function creditReversal(OrderPayment $payment, BigDecimal $amount): WalletTransaction
    {
        $order = $payment->order;

        return $this->post(
            $order->user_id,
            $payment->currency_id,
            WalletTransaction::TYPE_ORDER_PAYMENT_REVERSAL,
            $amount,
            "payment:{$payment->id}:reversal",
            ['order_id' => $order->id, 'order_payment_id' => $payment->id],
        );
    }

    /** Credit a refund to the customer's wallet in the order currency. */
    public function creditRefund(OrderRefund $refund): WalletTransaction
    {
        return $this->post(
            $refund->order->user_id,
            $refund->currency_id,
            WalletTransaction::TYPE_ORDER_REFUND,
            Money::of((string) $refund->amount),
            "refund:{$refund->id}",
            ['order_id' => $refund->order_id, 'order_refund_id' => $refund->id],
        );
    }

    protected function post(int $userId, int $currencyId, string $type, BigDecimal $amount, string $key, array $refs): WalletTransaction
    {
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new BusinessRuleException(__('admin.errors.amount_must_be_positive'));
        }

        return DB::transaction(function () use ($userId, $currencyId, $type, $amount, $key, $refs) {
            $existing = WalletTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            Wallet::query()->firstOrCreate(['user_id' => $userId, 'currency_id' => $currencyId]);
            $wallet = Wallet::query()->where('user_id', $userId)->where('currency_id', $currencyId)->lockForUpdate()->firstOrFail();

            $isDebit = $type === WalletTransaction::TYPE_ORDER_PAYMENT;
            $balance = Money::of((string) $wallet->balance);
            $newBalance = $isDebit ? $balance->minus($amount) : $balance->plus($amount);

            if ($newBalance->isLessThan(0)) {
                throw new BusinessRuleException(__('admin.errors.insufficient_wallet_balance'));
            }

            Wallet::query()->whereKey($wallet->id)->update([
                'balance' => Money::toStorage($newBalance),
                'updated_at' => now(),
            ]);

            return WalletTransaction::create(array_merge($refs, [
                'wallet_id' => $wallet->id,
                'type' => $type,
                'direction' => $isDebit ? 'debit' : 'credit',
                'amount' => Money::toStorage($amount),
                'balance_after' => Money::toStorage($newBalance),
                'idempotency_key' => $key,
            ]));
        });
    }

    /** Ledger consistency check: balance == Σ credits − Σ debits. */
    public function isConsistent(Wallet $wallet): bool
    {
        $credits = (string) (WalletTransaction::where('wallet_id', $wallet->id)->where('direction', 'credit')->sum('amount') ?: '0');
        $debits = (string) (WalletTransaction::where('wallet_id', $wallet->id)->where('direction', 'debit')->sum('amount') ?: '0');

        return Money::of($credits)->minus($debits)->isEqualTo((string) $wallet->fresh()->balance);
    }
}
