<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Models\Role;

/**
 * WAFFRLI (2026-10-31). Admin roles (Spatie). The `super-admin` role can
 * never be edited or deleted from the panel.
 */
class RolePolicy extends AdminResourcePolicy
{
    protected ?string $managePermission = 'roles.manage';

    public function update(Authenticatable $actor, mixed $record = null): bool
    {
        return parent::update($actor, $record) && ! ($record instanceof Role && $record->name === Admin::SUPER_ADMIN_ROLE);
    }

    public function delete(Authenticatable $actor, mixed $record = null): bool
    {
        return parent::delete($actor, $record) && ! ($record instanceof Role && $record->name === Admin::SUPER_ADMIN_ROLE);
    }
}
