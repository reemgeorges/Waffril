<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P1 (2026-10-31) — platform drivers (separate auth domain from
 * customers and admins; accounts are created by admins, password login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 16)->unique('uq_drivers_phone');
            $table->string('password');
            $table->foreignId('city_id')->constrained('cities', 'id', 'fk_drivers_city')->restrictOnDelete();
            $table->string('vehicle_type', 30)->nullable();
            $table->string('vehicle_plate', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('availability', 16)->default('offline');
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->string('language', 2)->default('ar');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['city_id', 'is_active', 'availability'], 'idx_drivers_city_active_availability');
        });

        SchemaConstraints::addCheck('drivers', 'chk_drivers_phone_e164', "phone REGEXP '^[+][1-9][0-9]{6,14}$'");
        SchemaConstraints::addCheck('drivers', 'chk_drivers_availability', "availability IN ('offline', 'available')");
        SchemaConstraints::addCheck('drivers', 'chk_drivers_language', "language IN ('ar', 'en')");
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
