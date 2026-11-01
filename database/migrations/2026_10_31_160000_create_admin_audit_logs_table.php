<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI admin panel (2026-10-31) — append-only audit trail of
 * important admin operations (required by the Filament admin scope; this
 * table is an addition to the frozen 65-table ERD). Never stores secrets,
 * passwords or OTP codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins', 'id', 'fk_aal_admin')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id'], 'idx_aal_auditable');
            $table->index(['action', 'created_at'], 'idx_aal_action_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
