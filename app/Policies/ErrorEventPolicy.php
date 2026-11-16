<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-11-16). Error groups are written only by the error monitor;
 * admins with errors.view read them, errors.manage resolves/reopens/ignores
 * them (actions on the resource, audited). Nobody creates or edits them.
 */
class ErrorEventPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'errors.view';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
