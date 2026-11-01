<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Immutable snapshots.
 */
class PlusCyclePolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'plus.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
