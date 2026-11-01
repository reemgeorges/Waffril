<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P5 money (2026-10-31).
 *
 * - payment_methods: wallet + cash at launch; `online` kind reserved for
 *   future electronic methods (structure only).
 * - wallets: one balance per (customer, currency), never negative, never
 *   converted. Balances change ONLY through wallet_transactions (no top-up,
 *   no withdrawal, no manual admin credit/debit).
 * - driver_cash_settlements: daily driver → platform cash reconciliation
 *   per currency. There is no merchant/store settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique('uq_payment_methods_code');
            $table->string('kind', 16);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_wallets_user')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'fk_wallets_currency')->restrictOnDelete();
            $table->decimal('balance', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency_id'], 'uq_wallets_user_currency');
        });

        Schema::create('driver_cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers', 'id', 'fk_dcs_driver')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'fk_dcs_currency')->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('expected_amount', 18, 4)->default(0);
            $table->decimal('received_amount', 18, 4)->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('settled_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_dcs_admin')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'currency_id', 'business_date'], 'uq_dcs_driver_currency_day');
            $table->index('status');
        });

        SchemaConstraints::addCheck('payment_methods', 'chk_payment_methods_kind', "kind IN ('wallet', 'cash', 'online')");
        SchemaConstraints::addCheck('wallets', 'chk_wallets_balance', 'balance >= 0');
        SchemaConstraints::addCheck('driver_cash_settlements', 'chk_dcs_status', "status IN ('open', 'settled', 'disputed')");
        SchemaConstraints::addCheck('driver_cash_settlements', 'chk_dcs_amounts', 'expected_amount >= 0 AND (received_amount IS NULL OR received_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_cash_settlements');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('payment_methods');
    }
};
