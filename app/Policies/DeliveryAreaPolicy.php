<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30). Every DeliveryArea admin API route is wrapped in
 * `can:manage,App\Models\DeliveryArea` (routes/api/v1/platform.php). Since
 * 2026-10-31 the same permission also drives the Filament resource
 * abilities (view/create/update); records are deactivated, never deleted.
 */
class DeliveryAreaPolicy extends AdminResourcePolicy
{
    protected ?string $managePermission = PlatformPermissions::DELIVERY_MANAGE;

    protected bool $canDelete = false;

    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::DELIVERY_MANAGE);
    }
}
