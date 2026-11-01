<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Resolved only through PlusService actions (plus.approve).
 */
class PlusRequestPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'plus.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
