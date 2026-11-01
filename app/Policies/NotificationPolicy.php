<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-10-31). Persisted database notifications — read-only in
 * the panel (no FCM/real-time sending exists yet).
 */
class NotificationPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'notifications.view';

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
