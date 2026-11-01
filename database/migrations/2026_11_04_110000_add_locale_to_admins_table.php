<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-04) — each admin's preferred panel language (ar | en),
 * applied on every request, after login, and independently of other admins.
 * Additive and nullable: existing admins keep the platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admins', 'locale')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->string('locale', 5)->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'locale')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropColumn('locale');
            });
        }
    }
};
