<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — branches, weekly opening hours and
 * hour exceptions (closed windows = holidays / temporary closures; open
 * windows = special opening hours). Branches carry NO delivery fee or
 * radius rules: those are platform-wide (delivery_fee_rules).
 *
 * UNIQUE(id, store_id) is the target of the composite FK on
 * branch_products that keeps a branch's products within its own store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_branches_store')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities', 'id', 'fk_branches_city')->restrictOnDelete();
            $table->json('name');
            $table->json('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('phone', 16)->nullable();
            $table->decimal('min_order_amount', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_accepting_orders')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'store_id'], 'uq_branches_id_store');
            $table->index(['city_id', 'is_active', 'is_accepting_orders'], 'idx_branches_city_status');
            $table->index('store_id', 'idx_branches_store');
        });

        Schema::create('branch_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches', 'id', 'fk_boh_branch')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->unique(['branch_id', 'day_of_week', 'opens_at'], 'uq_boh_branch_day_open');
        });

        Schema::create('branch_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches', 'id', 'fk_bhe_branch')->restrictOnDelete();
            $table->string('kind', 10);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('reason')->nullable();
            $table->foreignId('created_by_admin_id')->constrained('admins', 'id', 'fk_bhe_admin')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'starts_at', 'ends_at'], 'idx_bhe_branch_window');
        });

        SchemaConstraints::addCheck('branches', 'chk_branches_coordinates', 'lat BETWEEN -90 AND 90 AND lng BETWEEN -180 AND 180');
        SchemaConstraints::addCheck('branches', 'chk_branches_min_order', 'min_order_amount IS NULL OR min_order_amount >= 0');
        SchemaConstraints::addCheck('branch_opening_hours', 'chk_boh_day', 'day_of_week <= 6');
        SchemaConstraints::addCheck('branch_opening_hours', 'chk_boh_times', 'opens_at <> closes_at');
        SchemaConstraints::addCheck('branch_hour_exceptions', 'chk_bhe_kind', "kind IN ('closed', 'open')");
        SchemaConstraints::addCheck('branch_hour_exceptions', 'chk_bhe_window', 'ends_at > starts_at');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hour_exceptions');
        Schema::dropIfExists('branch_opening_hours');
        Schema::dropIfExists('branches');
    }
};
