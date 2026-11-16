<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-16) — how trustworthy a delivery area's boundary is, so
 * the admin panel never presents an approximate shape as an official one.
 * Additive and nullable: existing areas (drawn by an admin) keep NULL and
 * behave exactly as before; nothing reads these columns for coverage or
 * pricing.
 *
 *  - boundary_quality: verified | approximate | unverified (NULL = entered by an admin)
 *  - boundary_source:  where the shape comes from, e.g. "OpenStreetMap relation 18354844"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_areas', function (Blueprint $table) {
            $table->string('boundary_quality', 20)->nullable()->after('boundary');
            $table->string('boundary_source', 255)->nullable()->after('boundary_quality');
        });

        SchemaConstraints::addCheck(
            'delivery_areas',
            'chk_delivery_areas_boundary_quality',
            "boundary_quality IS NULL OR boundary_quality IN ('verified', 'approximate', 'unverified')"
        );
    }

    public function down(): void
    {
        if (SchemaConstraints::isMySql()) {
            DB::statement('ALTER TABLE `delivery_areas` DROP CHECK `chk_delivery_areas_boundary_quality`');
        }
        Schema::table('delivery_areas', function (Blueprint $table) {
            $table->dropColumn(['boundary_quality', 'boundary_source']);
        });
    }
};
