<?php

namespace Database\Seeders;

use Database\Seeders\Essential\AdminRoleTemplatesSeeder;
use Database\Seeders\Essential\ConfiguredCurrencySeeder;
use Database\Seeders\Essential\PlatformSettingsSeeder;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI (2026-11-01) — foundational data, safe for every environment:
 * permissions + the super-admin role, starter employee roles, documented
 * setting defaults, explicitly configured currencies and the launch
 * payment methods. Idempotent; never overwrites operator data, never
 * creates an admin account.
 *
 *   php artisan db:seed --class=WaffrliEssentialSeeder
 */
class WaffrliEssentialSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminAccessSeeder::class,
            AdminRoleTemplatesSeeder::class,
            PlatformSettingsSeeder::class,
            ConfiguredCurrencySeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
