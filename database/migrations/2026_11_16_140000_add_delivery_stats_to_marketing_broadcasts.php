<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-16) — what actually happened to a marketing message, per
 * stage, so the panel never presents "processed" as "delivered to phones".
 * Additive; existing rows keep NULL / 0 and are shown as "not recorded".
 *
 *  - push_enabled:        whether phone notifications were on when sending started
 *  - stored_count:        in-app notifications saved (exact count from `notifications`)
 *  - push_no_device_count: recipients without an active registered device
 *  - push_queued_count:   push jobs queued (one per active device)
 *  - push_accepted_count: devices Firebase ACCEPTED the message for (not a display receipt)
 *  - push_invalid_count:  device tokens Firebase rejected as invalid/unregistered (deactivated)
 *  - push_skipped_count:  not sent on purpose (user online, duplicate, inactive or expired token)
 *  - push_failed_count:   pushes that failed after all retries
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->boolean('push_enabled')->nullable()->after('processed_count');
            $table->unsignedInteger('stored_count')->nullable()->after('push_enabled');
            $table->unsignedInteger('push_no_device_count')->default(0)->after('stored_count');
            $table->unsignedInteger('push_queued_count')->default(0)->after('push_no_device_count');
            $table->unsignedInteger('push_accepted_count')->default(0)->after('push_queued_count');
            $table->unsignedInteger('push_invalid_count')->default(0)->after('push_accepted_count');
            $table->unsignedInteger('push_skipped_count')->default(0)->after('push_invalid_count');
            $table->unsignedInteger('push_failed_count')->default(0)->after('push_skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['push_enabled', 'stored_count', 'push_no_device_count', 'push_queued_count', 'push_accepted_count', 'push_invalid_count', 'push_skipped_count', 'push_failed_count']);
        });
    }
};
