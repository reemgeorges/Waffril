<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-16, Phase 12) — who a marketing message goes to, and
 * live sending progress. Additive and backward compatible:
 *
 *  - recipient_type: customers | drivers | both. Existing rows (and any
 *    caller that does not send it) default to "customers", exactly the
 *    previous behaviour. `audience` (all_customers | city) and `city_id`
 *    keep their meaning: the optional city filter.
 *  - total_count: recipients counted when sending starts (NULL before).
 *  - processed_count: recipients handled so far (updated per chunk).
 *    recipients_count stays the final number, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->string('recipient_type', 16)->default('customers')->after('audience');
            $table->unsignedInteger('total_count')->nullable()->after('recipients_count');
            $table->unsignedInteger('processed_count')->default(0)->after('total_count');
        });

        SchemaConstraints::addCheck('marketing_broadcasts', 'chk_mb_recipient_type', "recipient_type IN ('customers', 'drivers', 'both')");
    }

    public function down(): void
    {
        if (SchemaConstraints::isMySql()) {
            DB::statement('ALTER TABLE `marketing_broadcasts` DROP CHECK `chk_mb_recipient_type`');
        }
        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['recipient_type', 'total_count', 'processed_count']);
        });
    }
};
