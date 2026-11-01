<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Moderation (hide/unhide) goes through ProductRatingService.
 */
class ProductRatingPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'ratings.moderate';

    protected ?string $managePermission = null;

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
