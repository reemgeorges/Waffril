<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3A (2026-10-25) — Secure Dynamic OTP Core.
 *
 * `App\Models\Otp` was referenced (inside a dead docblock comment in
 * BaseAuthModel, and live in the now-rewritten App\Support\OtpChannelHelper)
 * but never had a backing table — any OTP code path has always fataled with
 * "Class App\Models\Otp not found". This is a brand new, additive table.
 *
 * Deliberately NOT a plaintext `otp` column (the old, never-actually-working
 * OtpChannelHelper design): only `code_hash` is stored, verified via
 * Hash::check(), exactly like password storage elsewhere in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otps')) {
            return;
        }

        Schema::create('otps', function (Blueprint $table) {
            $table->id();

            $table->morphs('verifiable');
            $table->string('purpose');
            $table->string('channel');

            // Hash of the raw destination (email/phone actually dialled),
            // not the destination itself — lets rate limiting key off "this
            // email address", independent of which account row it belongs
            // to, without storing it in the clear a second time here.
            $table->string('destination_hash')->nullable();

            // Hash of the code only. Never store the raw code anywhere.
            $table->string('code_hash');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();

            // Hash of the requesting IP at issue time, for audit only — the
            // actual IP rate limit is enforced via Laravel's cache-backed
            // RateLimiter (see App\Services\Auth\OtpService), not by
            // querying this column.
            $table->string('ip_hash')->nullable();

            $table->timestamps();

            $table->index(['verifiable_type', 'verifiable_id', 'purpose'], 'otps_verifiable_purpose_idx');
            $table->index(['destination_hash', 'purpose'], 'otps_destination_purpose_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
