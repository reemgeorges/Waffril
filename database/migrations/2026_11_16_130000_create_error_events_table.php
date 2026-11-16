<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-16, Phase 9) — grouped application errors for the admin
 * panel (Monitoring → Errors). One row per fingerprint (exception class +
 * location + normalised message); repeats increase `occurrences`.
 * Messages and traces are scrubbed before they are stored (no tokens, OTPs,
 * passwords, emails, phone numbers or request bodies). New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 64)->unique('uq_error_events_fingerprint');
            $table->string('exception_class', 255);
            $table->string('message', 1000);
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('regressed_at')->nullable();
            $table->string('last_request_id', 64)->nullable();
            $table->string('last_method', 10)->nullable();
            $table->string('last_path', 500)->nullable();
            $table->string('last_route', 255)->nullable();
            $table->string('last_actor', 64)->nullable();
            $table->string('environment', 32);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_error_events_admin')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'last_seen_at'], 'idx_error_events_status_seen');
            $table->index('last_request_id', 'idx_error_events_request');
        });

        SchemaConstraints::addCheck('error_events', 'chk_error_events_status', "status IN ('open', 'resolved', 'ignored')");
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
