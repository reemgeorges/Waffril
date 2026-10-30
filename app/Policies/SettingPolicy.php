<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30). Every Setting admin route is wrapped in
 * `can:manage,App\Models\Setting` (routes/api/v1/platform.php), so one
 * permission gates the whole admin surface of this model.
 */
class SettingPolicy
{
    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::SETTINGS_MANAGE);
    }
}
