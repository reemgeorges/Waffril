<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Support\Auth\AdminPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WAFFRLI (2026-10-31) — idempotent system seeder for admin access:
 * every permission of AdminPermissions::catalogue() (admin guard) and the
 * `super-admin` role (unrestricted via AdminPermissions::allows()).
 *
 * It never creates an admin account and never assigns a role. Create the
 * first Super Admin deliberately with `php artisan waffrli:create-super-admin`.
 * Not called from DatabaseSeeder.
 */
class AdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AdminPermissions::all() as $name) {
            Permission::findOrCreate($name, AdminPermissions::GUARD);
        }

        Role::findOrCreate(Admin::SUPER_ADMIN_ROLE, AdminPermissions::GUARD);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
