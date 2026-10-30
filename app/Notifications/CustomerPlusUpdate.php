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
        $body = $this->decision === 'approved'
            ? ['en' => 'Your WAFFRLI PLUS request was approved.', 'ar' => 'تمت الموافقة على طلب وفرلي بلس.']
            : ['en' => 'Your WAFFRLI PLUS request was declined.', 'ar' => 'تم رفض طلب وفرلي بلس.'];

        return ['title' => ['en' => 'WAFFRLI PLUS', 'ar' => 'وفرلي بلس'], 'body' => $body];
    }

    protected function references(): array
    {
        return ['plus_request_id' => $this->request->id, 'request_type' => $this->request->type];
    }
}
