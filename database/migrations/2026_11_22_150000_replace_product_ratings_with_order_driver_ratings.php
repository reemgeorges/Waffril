<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rating now belongs to the delivered ORDER, independently from its DRIVER.
 * Prior product ratings are archived, never reinterpreted or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_product_ratings')) {
            throw new RuntimeException('legacy_product_ratings already exists: inspect the archive before migrating.');
        }
        if (! Schema::hasTable('product_ratings')) {
            throw new RuntimeException('Expected product_ratings to exist; cannot archive product ratings.');
        }

        Schema::create('order_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique('uq_order_ratings_order')->constrained('orders', 'id', 'fk_order_ratings_order')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_order_ratings_user')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 255)->nullable();
            $table->foreignId('hidden_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_order_ratings_admin')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at'], 'idx_order_ratings_user_date');
            $table->index(['is_hidden', 'rating'], 'idx_order_ratings_public');
        });

        Schema::create('driver_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique('uq_driver_ratings_order')->constrained('orders', 'id', 'fk_driver_ratings_order')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_driver_ratings_user')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('drivers', 'id', 'fk_driver_ratings_driver')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 255)->nullable();
            $table->foreignId('hidden_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_driver_ratings_admin')->restrictOnDelete();
            $table->timestamps();
            $table->index(['driver_id', 'is_hidden'], 'idx_driver_ratings_driver_public');
            $table->index(['user_id', 'created_at'], 'idx_driver_ratings_user_date');
        });

        SchemaConstraints::addCheck('order_ratings', 'chk_order_rating_range', 'rating BETWEEN 1 AND 5');
        SchemaConstraints::addCheck('driver_ratings', 'chk_driver_rating_range', 'rating BETWEEN 1 AND 5');

        // Keep ALL old records, including hidden reviews, in an inaccessible archive.
        Schema::rename('product_ratings', 'legacy_product_ratings');

        if (SchemaConstraints::isMySql()) {
            DB::statement('ALTER TABLE `products` DROP CHECK `chk_products_rating`');
        }
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rating_sum', 'rating_count']);
        });
    }

    public function down(): void
    {
        // Roll back the schema only. Ratings created in the new tables cannot
        // truthfully become product ratings and are removed on rollback.
        Schema::dropIfExists('driver_ratings');
        Schema::dropIfExists('order_ratings');
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('rating_count')->default(0);
        });
        if (Schema::hasTable('legacy_product_ratings')) {
            Schema::rename('legacy_product_ratings', 'product_ratings');
            // Restore original genuine, visible aggregates exactly.
            DB::table('product_ratings')->where('is_hidden', false)
                ->selectRaw('product_id, COUNT(*) AS rating_count, SUM(rating) AS rating_sum')
                ->groupBy('product_id')->orderBy('product_id')->chunk(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('products')->where('id', $row->product_id)->update([
                            'rating_count' => (int) $row->rating_count,
                            'rating_sum' => (int) $row->rating_sum,
                        ]);
                    }
                });
        }
        SchemaConstraints::addCheck('products', 'chk_products_rating', 'rating_sum >= rating_count AND rating_sum <= 5 * rating_count');
    }
};
