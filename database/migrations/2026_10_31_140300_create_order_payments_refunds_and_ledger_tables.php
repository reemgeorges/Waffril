<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P5 money (2026-10-31).
 *
 * order_payments: one row per method per order. Invariant (CHECK):
 *   reversed_amount + refunded_amount <= collected_amount <= amount
 * so nothing that was not actually collected can ever be reversed or
 * refunded.
 *
 * wallet_transactions: APPEND-ONLY ledger. Only order_payment (debit),
 * order_payment_reversal (credit) and order_refund (credit) exist — no
 * top-up, withdrawal or manual credit/debit type. UNIQUE(order_payment_id,
 * type) makes a double debit or double reversal impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_op_order')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods', 'id', 'fk_op_method')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'fk_op_currency')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('collected_amount', 18, 4)->default(0);
            $table->decimal('reversed_amount', 18, 4)->default(0);
            $table->decimal('refunded_amount', 18, 4)->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('collected_by_driver_id')->nullable()->constrained('drivers', 'id', 'fk_op_driver')->restrictOnDelete();
            $table->foreignId('driver_cash_settlement_id')->nullable()->constrained('driver_cash_settlements', 'id', 'fk_op_settlement')->restrictOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'payment_method_id'], 'uq_op_order_method');
            $table->unique(['provider', 'provider_reference'], 'uq_op_provider_reference');
            $table->index(['collected_by_driver_id', 'status', 'driver_cash_settlement_id'], 'idx_op_driver_settlement');
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_or_order')->restrictOnDelete();
            $table->foreignId('order_payment_id')->constrained('order_payments', 'id', 'fk_or_payment')->restrictOnDelete();
            $table->foreignId('order_cancellation_id')->nullable()->constrained('order_cancellations', 'id', 'fk_or_cancellation')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'fk_or_currency')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('reason_code', 32);
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 64)->unique('uq_or_idempotency');
            $table->foreignId('created_by_admin_id')->constrained('admins', 'id', 'fk_or_admin')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets', 'id', 'fk_wt_wallet')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('direction', 6);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->foreignId('order_id')->nullable()->constrained('orders', 'id', 'fk_wt_order')->restrictOnDelete();
            $table->foreignId('order_payment_id')->nullable()->constrained('order_payments', 'id', 'fk_wt_payment')->restrictOnDelete();
            $table->foreignId('order_refund_id')->nullable()->unique('uq_wt_refund')->constrained('order_refunds', 'id', 'fk_wt_refund')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique('uq_wt_idempotency');
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_payment_id', 'type'], 'uq_wt_payment_type');
            $table->index(['wallet_id', 'id'], 'idx_wt_wallet');
            $table->index('order_id', 'idx_wt_order');
        });

        SchemaConstraints::addCheck('order_payments', 'chk_op_status', "status IN ('pending', 'paid', 'failed', 'cancelled', 'reversed')");
        SchemaConstraints::addCheck(
            'order_payments',
            'chk_op_amounts',
            'amount > 0 AND collected_amount >= 0 AND reversed_amount >= 0 AND refunded_amount >= 0 '
            .'AND collected_amount <= amount AND reversed_amount + refunded_amount <= collected_amount'
        );
        SchemaConstraints::addCheck('order_refunds', 'chk_or_amount', 'amount > 0');
        SchemaConstraints::addCheck('wallet_transactions', 'chk_wt_type', "type IN ('order_payment', 'order_payment_reversal', 'order_refund')");
        SchemaConstraints::addCheck('wallet_transactions', 'chk_wt_direction', "direction IN ('credit', 'debit') AND (type = 'order_payment') = (direction = 'debit')");
        SchemaConstraints::addCheck('wallet_transactions', 'chk_wt_amounts', 'amount > 0 AND balance_after >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('order_payments');
    }
};
