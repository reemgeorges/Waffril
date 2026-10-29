<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B (2026-10-25) — critical security fix.
 *
 * Before this phase, App\Services\Basic\BaseAuthService::resetPassword()
 * updated an account's password from nothing but a client-supplied
 * actor+email/phone+new-password — with NO check that OTP verification
 * had ever happened for that account. Any anonymous caller could reset any
 * admin's or user's password outright. This table backs a dedicated,
 * single-use, short-lived, account-bound grant that must be presented
 * (and is atomically consumed, same pattern as App\Models\Otp) before a
 * password reset is allowed to proceed — see
 * App\Services\Auth\PasswordResetGrantService.
 *
 * Deliberately NOT a broad Sanctum personal access token (which is what
 * the separate, unrouted App\Services\Functional\ResetPasswordService used
 * instead): a Sanctum token's default abilities (['*']) would let a leaked
 * reset token do far more than reset a password. This grant can do exactly
 * one thing, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_grants')) {
            return;
        }

        Schema::create('password_reset_grants', function (Blueprint $table) {
            $table->id();
            $table->morphs('verifiable');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['verifiable_type', 'verifiable_id'], 'password_reset_grants_verifiable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_grants');
    }
};
