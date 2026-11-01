<?php

namespace App\Policies;

use App\Models\Admin;
use App\Support\Auth\AdminPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Phase 2B (2026-10-25). Governs managing Admin accounts themselves (not
 * admin-as-actor-over-other-resources, which is per-resource policies'
 * job). Auto-discovered by Laravel's default App\Models\X -> App\Policies\XPolicy
 * convention — not registered anywhere, and not yet gated behind any route
 * (no route currently authorizes against it; wiring that up is Phase 2B+
 * follow-up work for whichever controller ends up managing Admin accounts).
 *
 * Typed to Authenticatable (not Admin) for the same reason as UserPolicy:
 * Laravel's Gate invokes these methods with whatever actor is actually
 * being checked, so assuming it is always an Admin would TypeError the
 * moment a non-Admin actor is ever checked against this policy.
 */
class AdminPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $this->manages($actor);
    }

    public function view(Authenticatable $actor, Admin $target): bool
    {
        return $this->manages($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(Authenticatable $actor, Admin $target): bool
    {
        return $this->manages($actor);
    }

    public function delete(Authenticatable $actor, Admin $target): bool
    {
        return $this->manages($actor) && $actor->id !== $target->id;
    }

    public function restore(Authenticatable $actor, Admin $target): bool
    {
        return $this->manages($actor);
    }

    public function forceDelete(Authenticatable $actor, Admin $target): bool
    {
        // Permanent delete is never granted by the generic manage-* role;
        // it needs its own explicit, separately-granted permission.
        return false;
    }

    public function deleteAny(Authenticatable $actor): bool
    {
        return $this->manages($actor);
    }

    public function forceDeleteAny(Authenticatable $actor): bool
    {
        return false;
    }

    /**
     * Phase 2B role `manage-admin`, or (WAFFRLI 2026-10-31) the
     * `admins.manage` permission / Super Admin used by the admin panel.
     */
    protected function manages(Authenticatable $actor): bool
    {
        return ($actor instanceof Admin && $actor->hasRole('manage-admin'))
            || AdminPermissions::allows($actor, 'admins.manage');
    }
}
