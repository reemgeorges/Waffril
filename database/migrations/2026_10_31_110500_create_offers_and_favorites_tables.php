<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — public store offers (store, branch
 * or product scope; percent or fixed) and PRODUCT favorites (no store
 * favorites). No coupons exist anywhere in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_offers_store')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches', 'id', 'fk_offers_branch')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products', 'id', 'fk_offers_product')->restrictOnDelete();
            $table->json('name')->nullable();
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 18, 4);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_admin_id')->constrained('admins', 'id', 'fk_offers_admin')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'is_active', 'starts_at', 'ends_at'], 'idx_offers_store_window');
            $table->index('product_id', 'idx_offers_product');
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_favorites_user')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'id', 'fk_favorites_product')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'product_id']);
            $table->index('product_id', 'idx_favorites_product');
        });

        SchemaConstraints::addCheck('offers', 'chk_offers_type', "discount_type IN ('percent', 'fixed')");
        SchemaConstraints::addCheck('offers', 'chk_offers_value', "discount_value > 0 AND (discount_type <> 'percent' OR discount_value <= 100)");
        SchemaConstraints::addCheck('offers', 'chk_offers_window', 'ends_at IS NULL OR ends_at > starts_at');
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('offers');
    }
};
