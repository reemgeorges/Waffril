<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A (2026-10-25) — Configurable Authentication Foundation.
 *
 * `App\Models\Admin` already had live references across the codebase
 * (AdminAuthService, ResetPasswordService, CombinedAuthService,
 * CurrentActor, AdminAuthController) but no backing table/model existed —
 * hitting POST /api/v1/admin/login currently fatals with
 * "Class App\Models\Admin not found". This is a brand new, additive table;
 * it does not touch any existing table or data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admins')) {
            return;
        }

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // Phase 1 lifecycle convention (separate from deleted_at below).
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
