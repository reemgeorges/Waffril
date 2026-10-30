<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Orders are never created, edited or deleted by admins; workflow actions use OrderAdminService.
 */
class OrderPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'orders.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
