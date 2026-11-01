<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P5 orders (2026-10-31) — customer cancellation requests (one
 * open request per order) and the single, idempotent cancellation record
 * per order with its financial consequences. order_id FKs are RESTRICT
 * (MySQL forbids cascading FKs on generated-column base columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_ocr_order')->restrictOnDelete();
            $table->string('customer_note', 500)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('pending_marker')->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN order_id ELSE NULL END");
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_ocr_admin')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->timestamps();

            $table->unique('pending_marker', 'uq_ocr_one_pending');
            $table->index(['status', 'created_at'], 'idx_ocr_status_created');
        });

        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique('uq_oc_order')->constrained('orders', 'id', 'fk_oc_order')->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('cancelled_by', 16);
            $table->foreignId('admin_id')->nullable()->constrained('admins', 'id', 'fk_oc_admin')->restrictOnDelete();
            $table->foreignId('cancellation_request_id')->nullable()->constrained('order_cancellation_requests', 'id', 'fk_oc_request')->restrictOnDelete();
            $table->string('reason_code', 32);
            $table->string('note', 500)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers', 'id', 'fk_oc_driver')->restrictOnDelete();
            $table->string('driver_handling', 24)->default('not_applicable');
            $table->decimal('cash_collected_amount', 18, 4)->default(0);
            $table->decimal('wallet_reversed_amount', 18, 4)->default(0);
            $table->boolean('free_delivery_restored')->default(false);
            $table->string('idempotency_key', 64)->unique('uq_oc_idempotency');
            $table->timestamp('created_at')->nullable();
        });

        SchemaConstraints::addCheck('order_cancellation_requests', 'chk_ocr_status', "status IN ('pending', 'approved', 'rejected')");
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_from_status', "from_status IN ('pending', 'accepted', 'assigned', 'picked_up')");
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_by', "cancelled_by IN ('customer', 'admin')");
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_admin_required', "cancelled_by = 'customer' OR admin_id IS NOT NULL");
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_customer_pending_only', "cancelled_by = 'admin' OR from_status = 'pending'");
        SchemaConstraints::addCheck(
            'order_cancellations',
            'chk_oc_reason',
            "reason_code IN ('customer_request', 'product_unavailable', 'branch_unavailable', 'customer_unreachable', 'customer_refused', 'other')"
        );
        SchemaConstraints::addCheck(
            'order_cancellations',
            'chk_oc_driver_handling',
            "driver_handling IN ('not_applicable', 'returned_to_branch', 'disposed', 'retained_by_customer')"
        );
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_after_pickup_handling', "from_status <> 'picked_up' OR driver_handling <> 'not_applicable'");
        SchemaConstraints::addCheck('order_cancellations', 'chk_oc_amounts', 'cash_collected_amount >= 0 AND wallet_reversed_amount >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellations');
        Schema::dropIfExists('order_cancellation_requests');
    }
};
