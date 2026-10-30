<?php

namespace App\Policies;

/**
 * WAFFRLI (2026-11-03) — marketing messages: listed with notifications.view
 * or notifications.marketing; SENT only through the resource's "send"
 * action (MarketingBroadcastService checks notifications.marketing). Rows
 * are an audit trail: never edited or deleted.
 */
class MarketingBroadcastPolicy extends AdminResourcePolicy
{
    protected ?string $viewPermission = 'notifications.view';

    protected ?string $managePermission = 'notifications.marketing';

    protected bool $canCreate = false;

    protected bool $canUpdate = false;

    protected bool $canDelete = false;
}
