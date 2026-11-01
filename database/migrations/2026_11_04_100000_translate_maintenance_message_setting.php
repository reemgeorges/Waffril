<?php

use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-11-04) — `maintenance.message` becomes {ar, en}.
 *
 * Data-preserving: an existing plain-string value is kept verbatim and
 * assigned to ONE language by its script (Arabic letters → ar, otherwise
 * en). The other language stays empty (no generated translation); readers
 * fall back to the language that exists. Already-converted values are left
 * untouched, so the migration is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'maintenance.message')->first();
        if (! $row) {
            return;
        }
        $value = json_decode((string) $row->value, true);
        if (is_string($value)) {
            DB::table('settings')->where('id', $row->id)->update([
                'value' => json_encode(array_filter(PlatformSettings::translations($value)), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'maintenance.message')->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        if (is_array($value)) {
            // Back to one string: English if present, otherwise Arabic.
            DB::table('settings')->where('id', $row->id)->update([
                'value' => json_encode($value['en'] ?? $value['ar'] ?? null, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
