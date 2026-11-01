<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — store-owned products (one image
 * each), multi-category pivot, option groups (sizes / choices / add-ons)
 * and options.
 *
 * rating_sum / rating_count hold GENUINE, VISIBLE ratings only; the
 * admin placeholder rating lives in settings and is never written here.
 * UNIQUE(id, store_id) / UNIQUE(id, product_id) are composite-FK targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_products_store')->restrictOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'store_id'], 'uq_products_id_store');
            $table->index(['store_id', 'is_active'], 'idx_products_store_active');
        });

        Schema::create('product_category', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products', 'id', 'fk_pc_product')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories', 'id', 'fk_pc_category')->cascadeOnDelete();

            $table->primary(['product_id', 'category_id']);
            $table->index(['category_id', 'product_id'], 'idx_pc_category_product');
        });

        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products', 'id', 'fk_pog_product')->cascadeOnDelete();
            $table->json('name');
            $table->string('kind', 16)->default('choice');
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'product_id'], 'uq_pog_id_product');
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('option_group_id');
            $table->unsignedBigInteger('product_id');
            $table->json('name');
            $table->unsignedTinyInteger('max_quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['option_group_id', 'product_id'], 'fk_po_group_product')
                ->references(['id', 'product_id'])->on('product_option_groups')->cascadeOnDelete();
            $table->unique(['id', 'product_id'], 'uq_po_id_product');
            $table->index('option_group_id', 'idx_po_group');
        });

        SchemaConstraints::addCheck('products', 'chk_products_rating', 'rating_sum >= rating_count AND rating_sum <= 5 * rating_count');
        SchemaConstraints::addCheck('product_option_groups', 'chk_pog_kind', "kind IN ('size', 'choice', 'addon')");
        SchemaConstraints::addCheck('product_option_groups', 'chk_pog_selection', 'max_select >= 1 AND max_select >= min_select');
        SchemaConstraints::addCheck('product_option_groups', 'chk_pog_size_single', "kind <> 'size' OR (min_select = 1 AND max_select = 1)");
        SchemaConstraints::addCheck('product_options', 'chk_po_max_quantity', 'max_quantity >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('product_category');
        Schema::dropIfExists('products');
    }
};
