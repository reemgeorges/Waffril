<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Delete = soft delete.
 */
class OfferPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'catalog.view';

    protected ?string $managePermission = 'offers.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = true;
}
