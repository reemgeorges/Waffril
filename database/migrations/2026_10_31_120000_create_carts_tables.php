<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P3 (2026-10-31) — one active cart per (customer, branch).
 * Carts store NO prices (always priced live); a line is unique per
 * product + option configuration (configuration_hash = sha256 of the
 * sorted "option_id:qty" pairs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_carts_user')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches', 'id', 'fk_carts_branch')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id'], 'uq_carts_user_branch');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts', 'id', 'fk_cart_items_cart')->cascadeOnDelete();
            $table->foreignId('branch_product_id')->constrained('branch_products', 'id', 'fk_cart_items_bp')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->char('configuration_hash', 64);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'branch_product_id', 'configuration_hash'], 'uq_cart_items_line');
        });

        Schema::create('cart_item_options', function (Blueprint $table) {
            $table->foreignId('cart_item_id')->constrained('cart_items', 'id', 'fk_cio_item')->cascadeOnDelete();
            $table->foreignId('product_option_id')->constrained('product_options', 'id', 'fk_cio_option')->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity')->default(1);

            $table->primary(['cart_item_id', 'product_option_id']);
        });

        SchemaConstraints::addCheck('cart_items', 'chk_cart_items_quantity', 'quantity BETWEEN 1 AND 99');
        SchemaConstraints::addCheck('cart_item_options', 'chk_cio_quantity', 'quantity >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_options');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
