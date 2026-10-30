<?php

namespace App\Policies;

use App\Support\Auth\PlatformPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI P0 (2026-10-30). Every DeliveryArea admin route is wrapped in
 * `can:manage,App\Models\DeliveryArea` (routes/api/v1/platform.php), so one
 * permission gates the whole admin surface of this model.
 */
class DeliveryAreaPolicy
{
    public function manage(Authenticatable $actor): bool
    {
        return PlatformPermissions::allows($actor, PlatformPermissions::DELIVERY_MANAGE);
    }
}
