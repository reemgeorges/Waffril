<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — platform-wide delivery areas inside a city.
 *
 * `boundary` is a GeoJSON Polygon (outer ring only, [lng, lat] pairs)
 * stored as JSON and evaluated in PHP (bounding-box prefilter, then
 * point-in-polygon) rather than as a MySQL spatial type, so the same
 * logic runs on the SQLite test suite. The min/max columns are the
 * polygon's bounding box, derived by DeliveryAreaService on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')
                ->constrained('cities', 'id', 'fk_delivery_areas_city')
                ->restrictOnDelete();
            $table->json('name');
            $table->json('boundary');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['city_id', 'is_active', 'min_lat', 'max_lat'], 'idx_delivery_areas_city_bbox');
        });

        SchemaConstraints::addCheck(
            'delivery_areas',
            'chk_delivery_areas_bbox',
            'min_lat <= max_lat AND min_lng <= max_lng'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_areas');
    }
};
