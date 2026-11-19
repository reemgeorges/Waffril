<?php

namespace App\Policies;

/** Reuse the existing app-content permission, no new role assignments. */
class HomeSliderPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'content.manage';

    protected ?string $managePermission = 'content.manage';
}
