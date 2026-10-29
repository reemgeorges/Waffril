<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A (2026-10-25) — Configurable Authentication Foundation.
 *
 * `App\Models\DeviceToken` was already referenced by
 * App\Services\Basic\BaseAuthService (issueToken/syncDeviceToken/
 * revokeDeviceToken) and App\Console\Commands\CleanupExpiredTokensCommand,
 * but no model/table existed — any code path touching it currently fatals
 * with "Class App\Models\DeviceToken not found". Brand new, additive table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('token')->unique();
            $table->string('platform')->default('unknown');
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id', 'device_id'], 'device_tokens_owner_device_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
