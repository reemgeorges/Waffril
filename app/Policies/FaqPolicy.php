<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-11-12) — app content (Terms & Conditions, FAQ): viewing and
 * editing need content.manage. Delete = soft delete (restorable).
 */
class FaqPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'content.manage';

    protected ?string $managePermission = 'content.manage';
}
