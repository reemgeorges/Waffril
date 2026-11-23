<?php

namespace App\Policies;

class DriverRatingPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'ratings.moderate';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
