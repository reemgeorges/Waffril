<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nullable override: existing plans retain the platform-wide default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plus_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_deliveries_override')->nullable()->after('free_deliveries_per_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('plus_plans', function (Blueprint $table) {
            $table->dropColumn('free_deliveries_override');
        });
    }
};
