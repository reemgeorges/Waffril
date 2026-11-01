<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P6 (2026-10-31) — PRODUCT ratings only (no store or driver
 * ratings): one per (customer, product), tied to a delivered order, with
 * admin moderation. Hidden ratings are excluded from products.rating_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_prt_user')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', 'id', 'fk_prt_product')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders', 'id', 'fk_prt_order')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_prt_admin')->restrictOnDelete();
            $table->string('hidden_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id'], 'uq_prt_user_product');
            $table->index(['product_id', 'is_hidden'], 'idx_prt_product_hidden');
        });

        SchemaConstraints::addCheck('product_ratings', 'chk_prt_rating', 'rating BETWEEN 1 AND 5');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ratings');
    }
};
