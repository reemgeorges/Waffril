<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — store categories, stores (one logo
 * each, no merchant login) and the many-to-many pivot between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug', 120)->unique('uq_store_categories_slug');
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug', 120)->unique('uq_stores_slug');
            $table->string('logo')->nullable();
            $table->json('description')->nullable();
            $table->string('contact_phone', 16)->nullable();
            $table->string('contact_whatsapp', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('store_store_category', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_ssc_store')->cascadeOnDelete();
            $table->foreignId('store_category_id')->constrained('store_categories', 'id', 'fk_ssc_category')->cascadeOnDelete();

            $table->primary(['store_id', 'store_category_id']);
            $table->index('store_category_id', 'idx_ssc_category');
        });

        SchemaConstraints::addCheck('stores', 'chk_stores_contact_phone', "contact_phone IS NULL OR contact_phone REGEXP '^[+][1-9][0-9]{6,14}$'");
        SchemaConstraints::addCheck('stores', 'chk_stores_contact_whatsapp', "contact_whatsapp IS NULL OR contact_whatsapp REGEXP '^[+][1-9][0-9]{6,14}$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('store_store_category');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('store_categories');
    }
};
