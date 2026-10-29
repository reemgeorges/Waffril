<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Phase 2B (2026-10-25). Governs an Admin managing User accounts.
 *
 * Typed to the generic Authenticatable contract (not App\Models\Admin)
 * deliberately: Laravel's Gate invokes a policy method with whichever actor
 * is actually being checked, and since this app has multiple guards/models,
 * assuming the actor is always an Admin instance would TypeError the moment
 * anything ever checks this policy for a non-Admin actor. Only an actual
 * Admin instance is granted anything here — a User (or any other actor)
 * managing other User accounts is a different, not-yet-defined policy that
 * this phase does not invent.
 */
class UserPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $actor instanceof Admin && $actor->hasRole('manage-user');
    }

    public function view(Authenticatable $actor, User $target): bool
    {
        return $actor instanceof Admin && $actor->hasRole('manage-user');
    }

    public function update(Authenticatable $actor, User $target): bool
    {
        return $actor instanceof Admin && $actor->hasRole('manage-user');
    }

    public function delete(Authenticatable $actor, User $target): bool
    {
        return $actor instanceof Admin && $actor->hasRole('manage-user');
    }

    public function restore(Authenticatable $actor, User $target): bool
    {
        return $actor instanceof Admin && $actor->hasRole('manage-user');
    }

    public function forceDelete(Authenticatable $actor, User $target): bool
    {
        return false;
    }
}
