<?php

namespace Database\Seeders\Essential;

use App\Models\Setting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI (2026-11-01) — stores the documented default of every platform
 * setting that has one, only where the key is missing. Values an operator
 * already saved are never overwritten. Settings without a default (support
 * phone, placeholder rating, …) are left for the operator.
 */
class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PlatformSettings::definitions() as $key => $definition) {
            if ($definition['default'] === null || Setting::query()->where('key', $key)->exists()) {
                continue;
            }

            Setting::create(['key' => $key, 'value' => $definition['default'], 'is_monetary' => (bool) ($definition['monetary'] ?? false)]);
        }
    }
}
