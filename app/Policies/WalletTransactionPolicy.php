<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Append-only ledger.
 */
class WalletTransactionPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'payments.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
