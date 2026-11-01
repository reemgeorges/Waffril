<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Plan-level discounts; changes apply to future cycles only.
 */
class PlusStoreDiscountPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'plus.view';

    protected ?string $managePermission = 'plus.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = true;
}
