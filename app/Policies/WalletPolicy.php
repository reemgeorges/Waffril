<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Balances are never edited by admins.
 */
class WalletPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'payments.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
