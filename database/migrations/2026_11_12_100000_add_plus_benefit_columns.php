<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI PLUS benefits (2026-11-12). Additive only — no existing column or
 * value changes, so historical orders and confirmed cycles stay as they are.
 *
 *  - stores.plus_discount_percent: the store's general PLUS discount on the
 *    merchandise subtotal for active members (0 = none). Plan/member store
 *    discounts snapshotted in a cycle still take precedence.
 *  - plus_cycles.delivery_discount_percent: snapshot of the post-allowance
 *    delivery-fee discount for the cycle (null = cycle confirmed before this
 *    benefit existed: the current platform setting applies).
 *  - orders.plus_discount_percent / plus_delivery_discount_percent /
 *    plus_delivery_discount_amount: pricing snapshot on the order, so later
 *    setting or store changes never alter an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('plus_discount_percent', 5, 2)->default(0)->after('is_active');
        });

        Schema::table('plus_cycles', function (Blueprint $table) {
            $table->decimal('delivery_discount_percent', 5, 2)->nullable()->after('free_deliveries_used');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('plus_discount_percent', 5, 2)->nullable()->after('plus_discount_amount');
            $table->decimal('plus_delivery_discount_percent', 5, 2)->nullable()->after('delivery_fee');
            $table->decimal('plus_delivery_discount_amount', 18, 4)->default(0)->after('plus_delivery_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['plus_discount_percent', 'plus_delivery_discount_percent', 'plus_delivery_discount_amount']);
        });
        Schema::table('plus_cycles', function (Blueprint $table) {
            $table->dropColumn('delivery_discount_percent');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('plus_discount_percent');
        });
    }
};
