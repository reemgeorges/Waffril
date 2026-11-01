<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — delivery cities. GPS city detection picks
 * the nearest active city whose center is within radius_km of the
 * customer's position. Cities are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->decimal('radius_km', 6, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        SchemaConstraints::addCheck('cities', 'chk_cities_radius_positive', 'radius_km > 0');
        SchemaConstraints::addCheck('cities', 'chk_cities_lat_range', 'center_lat BETWEEN -90 AND 90');
        SchemaConstraints::addCheck('cities', 'chk_cities_lng_range', 'center_lng BETWEEN -180 AND 180');
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
