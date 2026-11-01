<?php

namespace App\Notifications\Channels;

use App\Jobs\Push\SendPushMessage;
use App\Services\Push\DeviceTokenService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — notification channel that queues one
 * SendPushMessage per ACTIVE device token of the notifiable. Does nothing
 * while waffrli.push.enabled is false. Never throws into business code.
 */
class PushChannel
{
    public function __construct(protected DeviceTokenService $tokens) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! config('waffrli.push.enabled') || ! method_exists($notification, 'toPush')) {
            return;
        }

        $message = $notification->toPush($notifiable);
        $presence = method_exists($notifiable, 'receivesBroadcastNotificationsOn')
            ? 'private-'.$notifiable->receivesBroadcastNotificationsOn($notification)
            : null;

        foreach ($this->tokens->activeFor($notifiable) as $token) {
            try {
                SendPushMessage::dispatch((int) $token->id, $message, 'notification:'.$notification->id, $presence);
            } catch (Throwable $e) {
                Log::warning('push.dispatch_failed', ['token_id' => $token->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
