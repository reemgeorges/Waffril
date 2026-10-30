<?php

namespace App\Notifications;

use App\Models\PlusRequest;

/**
 * WAFFRLI (2026-11-03) — a PLUS request was approved or rejected (stored,
 * live on user.{id}, FCM). The admin note stays in the API.
 */
class CustomerPlusUpdate extends AppNotification
{
    public function __construct(public string $decision, public PlusRequest $request) {}

    public function event(): string
    {
        return 'plus.request_'.$this->decision;
    }

    protected function texts(): array
    {
        return [
            'title' => $this->both('notifications.customer.plus_title'),
            'body' => $this->both('notifications.customer.plus.'.$this->decision),
        ];
    }

    protected function references(): array
    {
        return ['plus_request_id' => $this->request->id, 'request_type' => $this->request->type];
    }
}
