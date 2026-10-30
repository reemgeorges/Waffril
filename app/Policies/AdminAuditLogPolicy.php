<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Append-only.
 */
class AdminAuditLogPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'audit.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
