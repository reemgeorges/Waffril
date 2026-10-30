<?php

namespace App\Policies;

use App\Support\Auth\AdminPermissions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WAFFRLI (2026-10-31) — permission-mapped policy base for admin
 * resources (Filament checks these abilities automatically).
 *
 * - viewAny/view: $viewPermission (falls back to $managePermission).
 * - create/update/delete/restore (+Any) and reorder: $managePermission,
 *   each individually switchable off for read-only or append-only data.
 * - forceDelete/forceDeleteAny/replicate: never allowed for anyone.
 */
abstract class AdminResourcePolicy
{
    protected ?string $viewPermission = null;

    protected ?string $managePermission = null;

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = true;

    protected function allows(?Authenticatable $actor, ?string $permission): bool
    {
        return $permission !== null && AdminPermissions::allows($actor, $permission);
    }

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->allows($actor, $this->viewPermission ?? $this->managePermission)
            || $this->allows($actor, $this->managePermission);
    }

    public function view(Authenticatable $actor, mixed $record = null): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->canCreate && $this->allows($actor, $this->managePermission);
    }

    public function update(Authenticatable $actor, mixed $record = null): bool
    {
        return $this->canUpdate && $this->allows($actor, $this->managePermission);
    }

    public function delete(Authenticatable $actor, mixed $record = null): bool
    {
        return $this->canDelete && $this->allows($actor, $this->managePermission);
    }

    public function deleteAny(Authenticatable $actor): bool
    {
        return $this->delete($actor);
    }

    public function restore(Authenticatable $actor, mixed $record = null): bool
    {
        return $this->canDelete && $this->allows($actor, $this->managePermission);
    }

    public function restoreAny(Authenticatable $actor): bool
    {
        return $this->restore($actor);
    }

    public function reorder(Authenticatable $actor): bool
    {
        return $this->update($actor);
    }

    public function forceDelete(Authenticatable $actor, mixed $record = null): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $actor): bool
    {
        return false;
    }

    public function replicate(Authenticatable $actor, mixed $record = null): bool
    {
        return false;
    }
}
