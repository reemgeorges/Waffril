<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B (2026-10-25). Supports the transactional-outbox fix in
 * App\Services\Auth\OtpService::issue(): delivery is no longer attempted
 * inside the DB transaction that creates the Otp row (sending a real,
 * irreversible external message from inside a transaction that might still
 * roll back for an unrelated reason is unsafe) — it is dispatched as a
 * queued job (App\Jobs\Otp\DeliverOtpNotificationJob) via ->afterCommit(),
 * which records its outcome back onto these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            if (! Schema::hasColumn('otps', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
            if (! Schema::hasColumn('otps', 'delivery_failed_at')) {
                $table->timestamp('delivery_failed_at')->nullable();
            }
            if (! Schema::hasColumn('otps', 'last_delivery_error')) {
                $table->string('last_delivery_error', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            foreach (['delivered_at', 'delivery_failed_at', 'last_delivery_error'] as $column) {
                if (Schema::hasColumn('otps', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
