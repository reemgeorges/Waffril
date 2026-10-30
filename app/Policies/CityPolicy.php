<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30). Every City admin route is wrapped in
 * `can:manage,App\Models\City` (routes/api/v1/platform.php), so one
 * permission gates the whole admin surface of this model.
 */
class CityPolicy
{
    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::CITIES_MANAGE);
    }
}
