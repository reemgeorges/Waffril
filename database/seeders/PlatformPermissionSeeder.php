<?php

namespace Database\Seeders;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * WAFFRLI P0 (2026-10-30).
 *
 * Idempotent, credential-free system seeder: creates the P0 admin
 * permissions under the `admin` guard. It never assigns them to any
 * admin or role, and it is NOT called from DatabaseSeeder — run it
 * deliberately:
 *
 *   php artisan db:seed --class=Database\\Seeders\\PlatformPermissionSeeder
 */
class PlatformPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PlatformPermissions::all() as $name) {
            Permission::findOrCreate($name, PlatformPermissions::GUARD);
        }
    }
}
