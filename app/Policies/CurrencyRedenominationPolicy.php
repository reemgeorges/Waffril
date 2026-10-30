<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). The re-denomination job is not implemented (P7); read-only.
 */
class CurrencyRedenominationPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'currencies.manage';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
