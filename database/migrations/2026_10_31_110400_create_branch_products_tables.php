<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — per-branch price/availability of a
 * product and per-branch price delta/availability of its options, in the
 * default currency.
 *
 * Composite FKs make the database itself reject a branch listing another
 * store's product, or an option priced on another product's branch row.
 * An option with no branch_product_options row is unavailable there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('store_id');
            $table->decimal('price', 18, 4);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->foreign(['branch_id', 'store_id'], 'fk_bp_branch_store')
                ->references(['id', 'store_id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['product_id', 'store_id'], 'fk_bp_product_store')
                ->references(['id', 'store_id'])->on('products')->cascadeOnDelete();
            $table->unique(['branch_id', 'product_id'], 'uq_bp_branch_product');
            $table->unique(['id', 'product_id'], 'uq_bp_id_product');
            $table->index('product_id', 'idx_bp_product');
        });

        Schema::create('branch_product_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_product_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_option_id');
            $table->decimal('price_delta', 18, 4)->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->foreign(['branch_product_id', 'product_id'], 'fk_bpo_bp_product')
                ->references(['id', 'product_id'])->on('branch_products')->cascadeOnDelete();
            $table->foreign(['product_option_id', 'product_id'], 'fk_bpo_option_product')
                ->references(['id', 'product_id'])->on('product_options')->cascadeOnDelete();
            $table->unique(['branch_product_id', 'product_option_id'], 'uq_bpo_bp_option');
        });

        SchemaConstraints::addCheck('branch_products', 'chk_bp_price', 'price >= 0');
        SchemaConstraints::addCheck('branch_product_options', 'chk_bpo_price_delta', 'price_delta >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_options');
        Schema::dropIfExists('branch_products');
    }
};
