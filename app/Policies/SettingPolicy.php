<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30). Every Setting admin API route is wrapped in
 * `can:manage,App\Models\Setting` (routes/api/v1/platform.php). Since
 * 2026-10-31 the same permission also drives the Filament resource
 * abilities (view/create/update); records are deactivated, never deleted.
 */
class SettingPolicy extends AdminResourcePolicy
{
    protected ?string $managePermission = PlatformPermissions::SETTINGS_MANAGE;

    protected bool $canDelete = false;

    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::SETTINGS_MANAGE);
    }
}
