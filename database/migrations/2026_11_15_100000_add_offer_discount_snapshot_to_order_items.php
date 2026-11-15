<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-15) — immutable offer snapshot on order items. Additive
 * only: no existing column or value changes.
 *
 * An order item already snapshots offer_id and the exact
 * offer_discount_amount; the offer's TYPE and PERCENTAGE lived only on the
 * editable offers row. These two columns record them at checkout, from the
 * same pricing calculation that produced the discount, inside the order
 * transaction:
 *
 *  - offer_discount_type: 'percent' | 'fixed'; null = no offer discount, or
 *    an item placed before this column existed (never backfilled);
 *  - offer_discount_percent: the offer's own percentage (DECIMAL(7,4), the
 *    precision of offers.discount_value), only for 'percent'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('offer_discount_type', 10)->nullable()->after('offer_discount_amount');
            $table->decimal('offer_discount_percent', 7, 4)->nullable()->after('offer_discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['offer_discount_type', 'offer_discount_percent']);
        });
    }
};
