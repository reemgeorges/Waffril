<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Delete = soft delete; confirmed cycles keep their snapshots.
 */
class PlusPlanPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'plus.view';

    protected ?string $managePermission = 'plus.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = true;
}
