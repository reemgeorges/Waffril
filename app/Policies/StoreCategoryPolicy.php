<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Delete = soft delete.
 */
class StoreCategoryPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'catalog.view';

    protected ?string $managePermission = 'catalog.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = true;
}
