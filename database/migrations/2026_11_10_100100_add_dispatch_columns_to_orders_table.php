<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — dispatch bookkeeping on orders.
 * Additive and nullable/defaulted: existing rows get NULL state, round 0,
 * attempts 0, so current orders and the current admin assignment flow are
 * unaffected.
 *
 *  - dispatch_state: NULL (no dispatch running), `offering` (an offer is
 *    pending or the next one is being chosen) or `no_driver` (waiting in
 *    the admin's pending-assignment queue). Only while the order is
 *    `accepted` (MySQL CHECK; enforced by the service everywhere).
 *  - dispatch_round / dispatch_attempts: current round and attempts used
 *    in it (the per-round cap).
 *  - no_driver_since / no_driver_alerted_at: when the order entered the
 *    queue and when admins were last alerted, so an unchanged condition
 *    is not re-announced.
 *  - assigned_via_offer_id: the accepted offer behind the assignment
 *    (NULL for direct admin assignments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('dispatch_state', 16)->nullable()->after('assigned_at');
            $table->unsignedSmallInteger('dispatch_round')->default(0)->after('dispatch_state');
            $table->unsignedSmallInteger('dispatch_attempts')->default(0)->after('dispatch_round');
            $table->timestamp('no_driver_since')->nullable()->after('dispatch_attempts');
            $table->timestamp('no_driver_alerted_at')->nullable()->after('no_driver_since');
            $table->foreignId('assigned_via_offer_id')->nullable()->after('no_driver_alerted_at')
                ->constrained('delivery_offers', 'id', 'fk_orders_assigned_offer')->restrictOnDelete();

            $table->index(['dispatch_state', 'status'], 'idx_orders_dispatch_state');
        });

        SchemaConstraints::addCheck('orders', 'chk_orders_dispatch_state', "dispatch_state IS NULL OR dispatch_state IN ('offering', 'no_driver')");
        SchemaConstraints::addCheck('orders', 'chk_orders_dispatch_accepted_only', "dispatch_state IS NULL OR status = 'accepted'");
        // no_driver_since is set exactly while the order waits in the queue.
        // COALESCE on both sides: a NULL comparison would make MySQL pass the check.
        SchemaConstraints::addCheck(
            'orders',
            'chk_orders_no_driver_since',
            "(COALESCE(dispatch_state, '') = 'no_driver' AND no_driver_since IS NOT NULL) OR (COALESCE(dispatch_state, '') <> 'no_driver' AND no_driver_since IS NULL)"
        );
    }

    public function down(): void
    {
        foreach (['chk_orders_no_driver_since', 'chk_orders_dispatch_accepted_only', 'chk_orders_dispatch_state'] as $check) {
            if (SchemaConstraints::isMySql()) {
                DB::statement("ALTER TABLE `orders` DROP CHECK `{$check}`");
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            // By name on MySQL; by column on SQLite (it stores no constraint
            // names, Laravel rebuilds the table).
            SchemaConstraints::isMySql()
                ? $table->dropForeign('fk_orders_assigned_offer')
                : $table->dropForeign(['assigned_via_offer_id']);
            $table->dropIndex('idx_orders_dispatch_state');
            $table->dropColumn(['dispatch_state', 'dispatch_round', 'dispatch_attempts', 'no_driver_since', 'no_driver_alerted_at', 'assigned_via_offer_id']);
        });
    }
};
