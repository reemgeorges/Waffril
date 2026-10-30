<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Read-only for admins.
 */
class UserAddressPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'customers.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
