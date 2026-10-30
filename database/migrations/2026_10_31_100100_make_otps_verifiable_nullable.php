<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P1 identity (2026-10-31) — an OTP for a first phone login is
 * requested before any customer row exists, so it is bound to the
 * hashed destination instead of a verifiable model. Existing rows are
 * untouched (they all have a verifiable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->string('verifiable_type')->nullable()->change();
            $table->unsignedBigInteger('verifiable_id')->nullable()->change();
        });

        SchemaConstraints::addCheck(
            'otps',
            'chk_otps_binding',
            'verifiable_id IS NOT NULL OR destination_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (SchemaConstraints::isMySql()) {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE `otps` DROP CHECK `chk_otps_binding`');
        }

        // Only reversible when no destination-only rows exist.
        Schema::table('otps', function (Blueprint $table) {
            $table->string('verifiable_type')->nullable(false)->change();
            $table->unsignedBigInteger('verifiable_id')->nullable(false)->change();
        });
    }
};
