<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — currencies and the single default currency.
 *
 * exchange_rate = units of THIS currency per 1 unit of the default
 * currency (the default itself is always exactly 1). Exactly one default
 * is guaranteed at the database level by the unique `default_marker`
 * generated column (NULL for every non-default row; MySQL and SQLite both
 * allow many NULLs in a unique index). Currencies are deactivated, never
 * deleted — later phases reference them with RESTRICT foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->json('name');
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->decimal('exchange_rate', 24, 12);
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('default_marker')->nullable()
                ->storedAs('CASE WHEN is_default = 1 THEN 1 ELSE NULL END');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rate_updated_at')->nullable();
            $table->foreignId('rate_updated_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_currencies_rate_updated_by_admin')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('default_marker', 'uq_currencies_single_default');
            $table->index('is_active');
        });

        SchemaConstraints::addCheck('currencies', 'chk_currencies_rate_positive', 'exchange_rate > 0');
        SchemaConstraints::addCheck('currencies', 'chk_currencies_decimals_range', 'decimals <= 4');
        SchemaConstraints::addCheck('currencies', 'chk_currencies_code_format', "code REGEXP '^[A-Z]{3}$'");
        SchemaConstraints::addCheck(
            'currencies',
            'chk_currencies_default_consistent',
            'is_default = 0 OR (is_active = 1 AND exchange_rate = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
