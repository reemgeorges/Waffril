<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Resolved through OrderAdminService.
 */
class OrderCancellationRequestPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'orders.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
