<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30).
 *
 * manage: list/show/create/update/activate/deactivate currencies and
 * initialize the FIRST default currency (CurrencyService refuses once a
 * default exists).
 *
 * changeDefault: switching an EXISTING default currency. Always denied in
 * P0 regardless of permissions — the controlled re-denomination job that
 * makes it safe is P7.
 */
class CurrencyPolicy
{
    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::CURRENCIES_MANAGE);
    }

    public function changeDefault(Authenticatable $actor): bool
    {
        return false;
    }
}
