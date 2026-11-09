<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — delivery offers. An admin offers an
 * ACCEPTED order to one driver; the driver accepts (the order becomes
 * `assigned`) or rejects; unanswered offers expire. Each row is one offer
 * and its outcome (the audit trail of a dispatch).
 *
 * Invariants enforced by the database (MySQL and SQLite):
 *  - at most ONE pending offer per order   (uq_do_one_pending_order);
 *  - at most ONE pending offer per driver  (uq_do_one_pending_driver);
 *  - a driver is offered an order at most once per dispatch round
 *    (uq_do_order_round_driver), so a driver who rejected or let an offer
 *    expire is never offered the same order again in that round;
 *  - attempts are unique per order and round (uq_do_order_round_attempt).
 * The marker columns are generated (pending → id, else NULL; NULLs never
 * collide). FKs are RESTRICT: MySQL forbids cascading FKs on base columns
 * of generated columns, and offers are history (never deleted).
 * MySQL additionally gets CHECK constraints on statuses and reasons. Every
 * nullable operand is tested with IS [NOT] NULL explicitly: MySQL treats an
 * UNKNOWN check result (NULL comparison) as passing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_do_order')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('drivers', 'id', 'fk_do_driver')->restrictOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16)->default('pending');
            $table->string('source', 8);
            $table->foreignId('offered_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_do_admin')->restrictOnDelete();
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('reject_reason', 24)->nullable();
            $table->string('reject_note', 500)->nullable();
            $table->string('cancel_reason', 24)->nullable();
            $table->json('selection_meta')->nullable();
            $table->unsignedBigInteger('pending_order_marker')->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN order_id ELSE NULL END");
            $table->unsignedBigInteger('pending_driver_marker')->nullable()
                ->storedAs("CASE WHEN status = 'pending' THEN driver_id ELSE NULL END");
            $table->timestamps();

            $table->unique('pending_order_marker', 'uq_do_one_pending_order');
            $table->unique('pending_driver_marker', 'uq_do_one_pending_driver');
            $table->unique(['order_id', 'round', 'driver_id'], 'uq_do_order_round_driver');
            $table->unique(['order_id', 'round', 'attempt'], 'uq_do_order_round_attempt');
            $table->index(['status', 'expires_at'], 'idx_do_status_expires');
            $table->index(['driver_id', 'offered_at'], 'idx_do_driver_offered');
        });

        SchemaConstraints::addCheck('delivery_offers', 'chk_do_status', "status IN ('pending', 'accepted', 'rejected', 'expired', 'cancelled')");
        SchemaConstraints::addCheck('delivery_offers', 'chk_do_source', "source IN ('admin', 'auto')");
        SchemaConstraints::addCheck('delivery_offers', 'chk_do_admin_source', "source <> 'admin' OR offered_by_admin_id IS NOT NULL");
        SchemaConstraints::addCheck('delivery_offers', 'chk_do_round_attempt', 'round >= 1 AND attempt >= 1');
        SchemaConstraints::addCheck('delivery_offers', 'chk_do_window', 'expires_at > offered_at');
        SchemaConstraints::addCheck('delivery_offers', 'chk_do_responded', "(status = 'pending' AND responded_at IS NULL) OR (status <> 'pending' AND responded_at IS NOT NULL)");
        SchemaConstraints::addCheck(
            'delivery_offers',
            'chk_do_reject_reason',
            "(status = 'rejected' AND reject_reason IS NOT NULL AND reject_reason IN ('too_far', 'busy', 'vehicle_issue', 'other')) OR (status <> 'rejected' AND reject_reason IS NULL)"
        );
        SchemaConstraints::addCheck(
            'delivery_offers',
            'chk_do_cancel_reason',
            "(status = 'cancelled' AND cancel_reason IS NOT NULL AND cancel_reason IN ('admin_override', 'admin_restart', 'order_cancelled', 'order_unassignable', 'driver_unavailable')) OR (status <> 'cancelled' AND cancel_reason IS NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_offers');
    }
};
