<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30).
 *
 * manage: list/show/create/update/activate/deactivate currencies and
 * initialize the FIRST default currency (CurrencyManagement refuses once a
 * default exists). Currencies are never deleted.
 *
 * changeDefault: switching an EXISTING default currency. Always denied —
 * even for a Super Admin — until the controlled re-denomination job (P7)
 * exists.
 */
class CurrencyPolicy extends AdminResourcePolicy
{
    protected ?string $managePermission = PlatformPermissions::CURRENCIES_MANAGE;

    protected bool $canDelete = false;

    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::CURRENCIES_MANAGE);
    }

    public function changeDefault(Authenticatable $actor): bool
    {
        return false;
    }
}
