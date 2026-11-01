<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-03) — admin marketing messages (permission
 * notifications.marketing). Additive; the audit of who sent what to whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('title_en', 120);
            $table->string('title_ar', 120);
            $table->string('body_en', 500);
            $table->string('body_ar', 500);
            $table->string('audience', 20)->default('all_customers');
            $table->foreignId('city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_broadcasts');
    }
};
