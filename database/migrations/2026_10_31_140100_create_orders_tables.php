<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P5 orders (2026-10-31). Orders are never deleted or edited
 * after submission; every amount is in the order's own (frozen) currency
 * and every customer-visible value is snapshotted (address, product
 * names, option names, prices, exchange rate, distance and its method).
 *
 * Status: pending → accepted → assigned → picked_up → delivered, or
 * cancelled (see App\Services\Order\OrderStateMachine). Cancellation
 * details live in order_cancellations (one per order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 20)->unique('uq_orders_number');
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_orders_user')->restrictOnDelete();
            $table->char('client_request_id', 36);
            $table->foreignId('branch_id')->constrained('branches', 'id', 'fk_orders_branch')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities', 'id', 'fk_orders_city')->restrictOnDelete();
            $table->foreignId('delivery_area_id')->nullable()->constrained('delivery_areas', 'id', 'fk_orders_area')->restrictOnDelete();
            $table->foreignId('delivery_fee_rule_id')->nullable()->constrained('delivery_fee_rules', 'id', 'fk_orders_fee_rule')->restrictOnDelete();
            $table->foreignId('user_address_id')->nullable()->constrained('user_addresses', 'id', 'fk_orders_address')->nullOnDelete();
            $table->json('delivery_address');
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->decimal('delivery_distance_km', 8, 3);
            $table->string('distance_method', 32);
            $table->string('type', 16);
            $table->timestamp('scheduled_for')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('urgent_at')->nullable();
            $table->foreignId('base_currency_id')->constrained('currencies', 'id', 'fk_orders_base_currency')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'fk_orders_currency')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('offer_discount_total', 18, 4)->default(0);
            $table->decimal('plus_discount_amount', 18, 4)->default(0);
            $table->decimal('delivery_fee_original', 18, 4);
            $table->decimal('delivery_fee', 18, 4);
            $table->decimal('total', 18, 4);
            $table->foreignId('plus_cycle_id')->nullable()->constrained('plus_cycles', 'id', 'fk_orders_plus_cycle')->restrictOnDelete();
            $table->foreignId('plus_cycle_discount_id')->nullable()->constrained('plus_cycle_discounts', 'id', 'fk_orders_plus_discount')->restrictOnDelete();
            $table->boolean('free_delivery_applied')->default(false);
            $table->string('customer_notes', 500)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers', 'id', 'fk_orders_driver')->restrictOnDelete();
            $table->foreignId('accepted_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_orders_accepted_admin')->restrictOnDelete();
            $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_orders_assigned_admin')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id'], 'uq_orders_user_client_request');
            $table->index(['user_id', 'created_at'], 'idx_orders_user_created');
            $table->index(['branch_id', 'status'], 'idx_orders_branch_status');
            $table->index(['status', 'type', 'scheduled_for'], 'idx_orders_status_schedule');
            $table->index(['driver_id', 'status'], 'idx_orders_driver_status');
            $table->index('urgent_at', 'idx_orders_urgent');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_order_items_order')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'id', 'fk_order_items_product')->restrictOnDelete();
            $table->foreignId('branch_product_id')->nullable()->constrained('branch_products', 'id', 'fk_order_items_bp')->nullOnDelete();
            $table->json('product_name');
            $table->string('product_image')->nullable();
            $table->decimal('base_unit_price', 18, 4);
            $table->decimal('options_unit_price', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_subtotal', 18, 4);
            $table->foreignId('offer_id')->nullable()->constrained('offers', 'id', 'fk_order_items_offer')->restrictOnDelete();
            $table->decimal('offer_discount_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('order_id', 'idx_order_items_order');
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items', 'id', 'fk_oio_item')->cascadeOnDelete();
            $table->foreignId('product_option_id')->nullable()->constrained('product_options', 'id', 'fk_oio_option')->nullOnDelete();
            $table->json('group_name');
            $table->json('option_name');
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->decimal('unit_price_delta', 18, 4)->default(0);
            $table->decimal('total_price_delta', 18, 4)->default(0);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_osh_order')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers', 'id', 'fk_osh_driver')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at'], 'idx_osh_order_created');
        });

        $statuses = "'pending', 'accepted', 'assigned', 'picked_up', 'delivered', 'cancelled'";
        SchemaConstraints::addCheck('orders', 'chk_orders_type', "type IN ('immediate', 'scheduled')");
        SchemaConstraints::addCheck('orders', 'chk_orders_schedule', "(type = 'scheduled') = (scheduled_for IS NOT NULL)");
        SchemaConstraints::addCheck('orders', 'chk_orders_status', "status IN ({$statuses})");
        SchemaConstraints::addCheck('orders', 'chk_orders_rate', 'exchange_rate > 0');
        SchemaConstraints::addCheck(
            'orders',
            'chk_orders_amounts',
            'subtotal >= 0 AND offer_discount_total >= 0 AND plus_discount_amount >= 0 AND delivery_fee >= 0 '
            .'AND delivery_fee_original >= 0 AND delivery_fee <= delivery_fee_original AND total >= 0'
        );
        SchemaConstraints::addCheck('orders', 'chk_orders_total', 'total = subtotal - offer_discount_total - plus_discount_amount + delivery_fee');
        SchemaConstraints::addCheck('orders', 'chk_orders_free_delivery', 'free_delivery_applied = 0 OR (plus_cycle_id IS NOT NULL AND delivery_fee = 0)');
        SchemaConstraints::addCheck('order_items', 'chk_oi_quantity', 'quantity >= 1');
        SchemaConstraints::addCheck('order_items', 'chk_oi_unit_price', 'unit_price = base_unit_price + options_unit_price');
        SchemaConstraints::addCheck('order_items', 'chk_oi_line', 'line_subtotal = unit_price * quantity AND offer_discount_amount >= 0 AND offer_discount_amount <= line_subtotal AND line_total = line_subtotal - offer_discount_amount');
        SchemaConstraints::addCheck('order_item_options', 'chk_oio_total', 'quantity >= 1 AND total_price_delta = unit_price_delta * quantity');
        SchemaConstraints::addCheck('order_status_histories', 'chk_osh_actor', "actor_type IN ('customer', 'admin', 'driver', 'system')");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
