<?php

namespace App\Http\Traits;

trait HasFcmToken
{
    public function routeNotificationForFcm(): string|array|null
    {
        if (! method_exists($this, 'deviceTokens')) {
            return null;
        }

        $tokens = $this->deviceTokens()
            ->active()
            ->pluck('token')
            ->filter()
            ->values()
            ->all();

        return !empty($tokens) ? $tokens : null;
    }
}
