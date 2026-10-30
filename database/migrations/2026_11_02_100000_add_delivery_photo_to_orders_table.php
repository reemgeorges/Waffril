<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI driver API (2026-11-02) — ADDITIVE, nullable: path of the
 * optional proof-of-delivery photo a driver may attach when completing a
 * delivery. The file lives on the PRIVATE `local` disk (never public);
 * admins download it from the Filament order page. No data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_photo_path', 255)->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_photo_path');
        });
    }
};
