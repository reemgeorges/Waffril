<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI dispatch B4 (2026-11-10) — an admin may WITHDRAW a pending
 * delivery offer (the dispatch pauses until an admin sends a new offer).
 * Adds the cancel reason `admin_withdrawn` to the MySQL CHECK constraint
 * (SQLite has no CHECK constraints here; the column is a plain string).
 */
return new class extends Migration
{
    private const REASONS_B1 = "'admin_override', 'admin_restart', 'order_cancelled', 'order_unassignable', 'driver_unavailable'";

    public function up(): void
    {
        $this->replaceCheck(self::REASONS_B1.", 'admin_withdrawn'");
    }

    public function down(): void
    {
        // Rows withdrawn by an admin keep a meaningful reason under the B1 list.
        DB::table('delivery_offers')->where('cancel_reason', 'admin_withdrawn')->update(['cancel_reason' => 'admin_override']);
        $this->replaceCheck(self::REASONS_B1);
    }

    private function replaceCheck(string $reasons): void
    {
        if (! SchemaConstraints::isMySql()) {
            return;
        }
        DB::statement('ALTER TABLE `delivery_offers` DROP CHECK `chk_do_cancel_reason`');
        SchemaConstraints::addCheck(
            'delivery_offers',
            'chk_do_cancel_reason',
            "(status = 'cancelled' AND cancel_reason IS NOT NULL AND cancel_reason IN ({$reasons})) OR (status <> 'cancelled' AND cancel_reason IS NULL)"
        );
    }
};
