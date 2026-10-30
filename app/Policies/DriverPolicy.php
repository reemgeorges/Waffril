<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Drivers are deactivated, not deleted.
 */
class DriverPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'drivers.view';

    protected ?string $managePermission = 'drivers.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = false;
}
