<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — platform-wide delivery fee rules.
 *
 * Scope: area (city + area), city (city only) or global (neither).
 * Each rule is a distance band [distance_from_km, distance_to_km); the
 * fee is base_fee + per_km_fee * (distance - distance_from_km), in the
 * default currency. Branches never carry their own fee/radius rules.
 *
 * `scope_key` ('a:<area>', 'c:<city>' or 'g') is generated so one unique
 * index can stop two bands in the same scope from starting at the same
 * distance. General band overlap is checked in DeliveryFeeRuleService
 * (MySQL has no exclusion constraints).
 */
return new class extends Migration
{
    public function up(): void
    {
        $scopeKey = 'CASE'
            .' WHEN delivery_area_id IS NOT NULL THEN '.SchemaConstraints::concat("'a:'", 'delivery_area_id')
            .' WHEN city_id IS NOT NULL THEN '.SchemaConstraints::concat("'c:'", 'city_id')
            ." ELSE 'g' END";

        Schema::create('delivery_fee_rules', function (Blueprint $table) use ($scopeKey) {
            $table->id();
            $table->foreignId('city_id')->nullable()
                ->constrained('cities', 'id', 'fk_delivery_fee_rules_city')
                ->restrictOnDelete();
            $table->foreignId('delivery_area_id')->nullable()
                ->constrained('delivery_areas', 'id', 'fk_delivery_fee_rules_area')
                ->restrictOnDelete();
            $table->string('scope_key', 40)->storedAs($scopeKey);
            $table->decimal('distance_from_km', 6, 2)->default(0);
            $table->decimal('distance_to_km', 6, 2);
            $table->decimal('base_fee', 18, 4)->default(0);
            $table->decimal('per_km_fee', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_delivery_fee_rules_admin')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'distance_from_km'], 'uq_delivery_fee_rules_scope_band');
            $table->index(['scope_key', 'is_active'], 'idx_delivery_fee_rules_scope_active');
        });

        SchemaConstraints::addCheck(
            'delivery_fee_rules',
            'chk_delivery_fee_rules_area_needs_city',
            'delivery_area_id IS NULL OR city_id IS NOT NULL'
        );
        SchemaConstraints::addCheck(
            'delivery_fee_rules',
            'chk_delivery_fee_rules_band',
            'distance_from_km >= 0 AND distance_to_km > distance_from_km'
        );
        SchemaConstraints::addCheck(
            'delivery_fee_rules',
            'chk_delivery_fee_rules_fees',
            'base_fee >= 0 AND per_km_fee >= 0'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_fee_rules');
    }
};
