<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_sliders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->nullable()->unique('uq_home_sliders_code');
            $table->json('title');
            $table->json('subtitle')->nullable();
            $table->string('image', 255);
            $table->foreignId('store_id')->nullable()->constrained('stores', 'id', 'fk_home_slider_store')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order'], 'idx_home_slider_order');
            $table->index(['starts_at', 'ends_at'], 'idx_home_slider_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_sliders');
    }
};
