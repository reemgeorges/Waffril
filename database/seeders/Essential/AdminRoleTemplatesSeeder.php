<?php

namespace Database\Seeders\Essential;

use App\Support\Auth\AdminPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WAFFRLI (2026-11-01) — starter employee roles built from the permission
 * catalogue. A role is given its permissions only when it is CREATED here;
 * an existing role (possibly edited by a Super Admin) is never changed.
 * No role is ever assigned to anyone.
 */
class AdminRoleTemplatesSeeder extends Seeder
{
    /** @return array<string, list<string>> */
    public static function templates(): array
    {
        return [
            'operations-manager' => [
                'orders.view', 'orders.accept', 'orders.assign', 'orders.cancel', 'orders.cancel_after_pickup',
                'orders.resolve_cancellation_requests', 'refunds.create', 'drivers.view', 'drivers.track',
                'customers.view', 'payments.view', 'notifications.view',
            ],
            'dispatcher' => ['orders.view', 'orders.accept', 'orders.assign', 'drivers.view', 'drivers.track'],
            'catalog-manager' => ['catalog.view', 'catalog.manage', 'ratings.moderate', 'offers.manage'],
            'finance-officer' => ['payments.view', 'settlements.manage', 'refunds.create', 'orders.view', 'customers.view', 'drivers.view'],
            'plus-manager' => ['plus.view', 'plus.manage', 'plus.approve', 'customers.view'],
            'support-agent' => ['customers.view', 'orders.view', 'notifications.view'],
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $known = AdminPermissions::all();

        foreach (self::templates() as $name => $permissions) {
            $role = Role::query()->where('name', $name)->where('guard_name', AdminPermissions::GUARD)->first();
            if ($role) {
                continue;
            }

            Role::create(['name' => $name, 'guard_name' => AdminPermissions::GUARD])
                ->givePermissionTo(array_values(array_intersect($permissions, $known)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
