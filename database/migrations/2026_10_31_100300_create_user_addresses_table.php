<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-10-31) — customer delivery addresses. `user_id` is
 * RESTRICT (not CASCADE) because MySQL forbids cascading FKs on a base
 * column of a stored generated column (`default_marker`); customers are
 * never hard-deleted — anonymization removes addresses explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_user_addresses_user')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities', 'id', 'fk_user_addresses_city')->restrictOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('address_line', 255);
            $table->string('details', 255)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('default_marker')->nullable()
                ->storedAs('CASE WHEN is_default = 1 AND deleted_at IS NULL THEN user_id ELSE NULL END');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('default_marker', 'uq_user_addresses_one_default');
        });

        SchemaConstraints::addCheck('user_addresses', 'chk_user_addresses_coordinates', 'lat BETWEEN -90 AND 90 AND lng BETWEEN -180 AND 180');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
