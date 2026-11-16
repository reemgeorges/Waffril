<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-16, Phase 14) — indexes for the admin dashboard and the
 * order-list time windows. Additive only (no data change). Measured on a
 * 56k-order copy: see docs/waffrli/admin-panel-audit.md (performance).
 *
 * On MySQL 8 InnoDB, adding a secondary index is an online operation
 * (in-place, concurrent reads and writes allowed); run it in a quiet period
 * on very large tables all the same.
 *
 *  - orders(created_at): "received today", 14-day trends, the date filter
 *  - orders(status, delivered_at): "delivered today" and delivery times
 *  - order_status_histories(to_status, created_at): "cancelled today"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'idx_orders_created_at');
            $table->index(['status', 'delivered_at'], 'idx_orders_status_delivered');
        });
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->index(['to_status', 'created_at'], 'idx_osh_to_status_created');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropIndex('idx_osh_to_status_created');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_status_delivered');
            $table->dropIndex('idx_orders_created_at');
        });
    }
};
