<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — run log for the default-currency
 * re-denomination job.
 *
 * Created in P0 because later tables (P4 plus_cycle_discounts) reference
 * it; the job itself, its endpoint and the `currencies.change_default`
 * flow are P7 and are NOT implemented here. `inflight_marker` allows at
 * most one pending/running run at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_redenominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id')
                ->constrained('currencies', 'id', 'fk_cur_redenom_from')
                ->restrictOnDelete();
            $table->foreignId('to_currency_id')
                ->constrained('currencies', 'id', 'fk_cur_redenom_to')
                ->restrictOnDelete();
            $table->decimal('conversion_rate', 24, 12);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('inflight_marker')->nullable()
                ->storedAs("CASE WHEN status IN ('pending', 'running') THEN 1 ELSE NULL END");
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('requested_by_admin_id')
                ->constrained('admins', 'id', 'fk_cur_redenom_admin')
                ->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique('inflight_marker', 'uq_cur_redenom_single_inflight');
        });

        SchemaConstraints::addCheck(
            'currency_redenominations',
            'chk_cur_redenom_status',
            "status IN ('pending', 'running', 'completed', 'failed')"
        );
        SchemaConstraints::addCheck('currency_redenominations', 'chk_cur_redenom_rate_positive', 'conversion_rate > 0');
        SchemaConstraints::addCheck(
            'currency_redenominations',
            'chk_cur_redenom_distinct_currencies',
            'from_currency_id <> to_currency_id'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_redenominations');
    }
};
