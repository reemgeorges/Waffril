<?php

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30) — admin permissions introduced by P0.
 *
 * Seeded (never assigned) by Database\Seeders\PlatformPermissionSeeder
 * under the `admin` guard. Granting them to an admin (directly or via a
 * role) is always a deliberate, separate human action.
 *
 * CURRENCIES_CHANGE_DEFAULT is created now but intentionally unused until
 * P7: no P0 endpoint can switch an existing default currency, even for
 * an admin holding it.
 */
final class PlatformPermissions
{
    public const SETTINGS_MANAGE = 'settings.manage';

    public const CURRENCIES_MANAGE = 'currencies.manage';

    public const CURRENCIES_CHANGE_DEFAULT = 'currencies.change_default';

    public const CITIES_MANAGE = 'cities.manage';

    public const DELIVERY_MANAGE = 'delivery.manage';

    public const GUARD = 'admin';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SETTINGS_MANAGE,
            self::CURRENCIES_MANAGE,
            self::CURRENCIES_CHANGE_DEFAULT,
            self::CITIES_MANAGE,
            self::DELIVERY_MANAGE,
        ];
    }

    /**
     * True only for an active Admin actor holding the permission on the
     * admin guard (directly or through a role), or a Super Admin.
     * checkPermissionTo() returns false instead of throwing when the
     * permission was never seeded. Delegates to AdminPermissions
     * (2026-10-31) so the API and the Filament panel share one rule.
     */
    public static function allows(?Authenticatable $actor, string $permission): bool
    {
        return AdminPermissions::allows($actor, $permission);
    }
}
