<?php

namespace App\Notifications\Channels;

use App\Jobs\Push\SendPushMessage;
use App\Services\Notifications\MarketingDeliveryStats;
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

        // 2026-11-16: marketing messages are counted per stage (identifiers only).
        $broadcastId = MarketingDeliveryStats::broadcastId((array) ($message['data'] ?? []));
        $tokens = $this->tokens->activeFor($notifiable);
        if ($tokens->isEmpty()) {
            MarketingDeliveryStats::increment($broadcastId, 'push_no_device_count');

            return;
        }

        foreach ($tokens as $token) {
            // Counted before dispatching: with QUEUE_CONNECTION=sync the job runs
            // inside dispatch() and records its own outcome (accepted, invalid,
            // skipped or failed), so queued = sum of outcomes in both modes.
            MarketingDeliveryStats::increment($broadcastId, 'push_queued_count');
            try {
                SendPushMessage::dispatch((int) $token->id, $message, 'notification:'.$notification->id, $presence);
            } catch (Throwable $e) {
                Log::warning('push.dispatch_failed', ['token_id' => $token->id, 'error' => $e->getMessage()]);
                // A sync job already counted its failure in failed(); a job that
                // could not even be queued is counted here.
                if (config('queue.default') !== 'sync') {
                    MarketingDeliveryStats::increment($broadcastId, 'push_failed_count');
                }
            }
        }
    }
}
