<?php

namespace App\Support\Auth;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI (2026-10-31) — the complete admin permission catalogue (admin
 * guard). Permissions are seeded by Database\Seeders\AdminAccessSeeder and
 * granted to employees only through roles/permissions an authorised admin
 * assigns. The `super-admin` role is unrestricted EXCEPT for abilities no
 * one may perform (hard-coded `false` in policies, e.g. editing the wallet
 * ledger, force-deleting records, switching the default currency in this
 * phase).
 */
final class AdminPermissions
{
    public const GUARD = 'admin';

    /** @return array<string, list<string>> permission => group (for the roles UI) */
    public static function catalogue(): array
    {
        return [
            'platform' => [
                PlatformPermissions::SETTINGS_MANAGE,
                PlatformPermissions::CURRENCIES_MANAGE,
                PlatformPermissions::CURRENCIES_CHANGE_DEFAULT,
                PlatformPermissions::CITIES_MANAGE,
                PlatformPermissions::DELIVERY_MANAGE,
            ],
            'customers' => ['customers.view', 'customers.suspend', 'customers.anonymize'],
            'drivers' => ['drivers.view', 'drivers.manage', 'drivers.track'],
            'catalog' => ['catalog.view', 'catalog.manage', 'ratings.moderate', 'offers.manage'],
            'orders' => [
                'orders.view', 'orders.accept', 'orders.assign', 'orders.cancel',
                'orders.cancel_after_pickup', 'orders.resolve_cancellation_requests', 'refunds.create',
            ],
            'finance' => ['payments.view', 'payment_methods.manage', 'settlements.manage'],
            'plus' => ['plus.view', 'plus.manage', 'plus.approve'],
            'system' => ['notifications.view', 'admins.manage', 'roles.manage', 'audit.view'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::catalogue()));
    }

    public static function allows(?Authenticatable $actor, string $permission): bool
    {
        if (! $actor instanceof Admin || ! $actor->is_active || $actor->trashed()) {
            return false;
        }

        return $actor->isSuperAdmin() || $actor->checkPermissionTo($permission, self::GUARD);
    }

    public static function current(): ?Admin
    {
        $admin = auth('admin_web')->user() ?? auth('admin')->user();

        return $admin instanceof Admin ? $admin : null;
    }

    public static function currentAllows(string $permission): bool
    {
        return self::allows(self::current(), $permission);
    }
}
