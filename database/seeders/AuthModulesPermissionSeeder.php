<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2B (2026-10-25).
 *
 * Idempotent, credential-free: creates exactly one "manage-{module}" role
 * per configured auth module, scoped to that module's own guard_name so
 * Spatie's guard resolution (which matches a model to a guard via
 * config('auth.guards')/config('auth.providers')) stays correct. Does not
 * assign the role to any account and does not create any user/admin row —
 * that is a deliberate, separate, human decision, never a side effect of
 * scaffolding (CLAUDE.md: "Never execute production seeders as a side
 * effect of scaffolding").
 *
 * Safe to run repeatedly: Role::findOrCreate() is itself idempotent.
 */
class AuthModulesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Phase 2B fix: a "manage-{module}" role describes who is allowed
        // to act (always config('auth_modules.management_guard'), Admin by
        // default), not which module's table is being acted upon. Seeding
        // "manage-user" under the 'user' guard made it unassignable to any
        // Admin actor ("no role named manage-user for guard admin").
        $managementGuard = config('auth_modules.management_guard', 'admin');

        foreach (config('auth_modules.modules', []) as $name => $module) {
            Role::findOrCreate("manage-{$name}", $managementGuard);
        }
    }
}
