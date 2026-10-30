<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Deactivate instead of delete.
 */
class PaymentMethodPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'payments.view';

    protected ?string $managePermission = 'payment_methods.manage';

    protected bool $canCreate = true;

    protected bool $canUpdate = true;

    protected bool $canDelete = false;
}
