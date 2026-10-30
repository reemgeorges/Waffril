<?php

namespace App\Notifications;

use App\Models\MarketingBroadcast;

/**
 * WAFFRLI (2026-11-03) — an admin marketing message (permission
 * notifications.marketing). Text written by the admin; no personal data.
 */
class MarketingMessage extends AppNotification
{
    public function __construct(public MarketingBroadcast $broadcast) {}

    public function event(): string
    {
        return 'marketing.message';
    }

    protected function texts(): array
    {
        $b = $this->broadcast;

        return [
            'title' => ['en' => $b->title_en, 'ar' => $b->title_ar],
            'body' => ['en' => $b->body_en, 'ar' => $b->body_ar],
        ];
    }

    protected function references(): array
    {
        return ['marketing_broadcast_id' => $this->broadcast->id];
    }
}
