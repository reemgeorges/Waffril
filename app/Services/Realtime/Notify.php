<?php

namespace App\Services\Realtime;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — sends notifications after the surrounding
 * transaction commits; a broadcast/push problem is logged and never fails
 * the order, payment or PLUS operation that caused it.
 *
 * Notification audit (2026-11-13): every recipient and every channel is
 * delivered on its own. Laravel stops at the first channel that throws, so
 * an unreachable Reverb (the "broadcast" channel, which publishes inline on
 * a sync queue) used to cancel the push to a closed app and the
 * notifications of every later recipient. Each recipient keeps one
 * notification id across its channels, so the push "notification_id" still
 * matches the stored in-app notification.
 */
class Notify
{
    /**
     * @param  bool  $storeWhenMuted  while notifications are muted (demo
     *                                seeding) still store the in-app record —
     *                                nothing is broadcast or pushed. Driver
     *                                order updates use it, as they always
     *                                left a stored notification in the demo.
     */
    public static function send(mixed $notifiables, BaseNotification $notification, bool $storeWhenMuted = false): void
    {
        if (config('waffrli.notifications_muted')) {
            if ($storeWhenMuted) {
                Notification::sendNow($notifiables, $notification, ['database']);
            }

            return; // demo seeding
        }
        DB::afterCommit(function () use ($notifiables, $notification) {
            foreach (self::recipients($notifiables) as $notifiable) {
                $copy = clone $notification;
                $copy->id ??= (string) Str::uuid();

                try {
                    $channels = (array) $copy->via($notifiable);
                } catch (Throwable $e) {
                    Log::warning('notify.failed', ['notification' => $notification::class, 'error' => $e->getMessage()]);

                    continue;
                }

                foreach ($channels as $channel) {
                    try {
                        Notification::sendNow($notifiable, $copy, [$channel]);
                    } catch (Throwable $e) {
                        Log::warning('notify.failed', [
                            'notification' => $notification::class,
                            'channel' => is_string($channel) ? $channel : get_debug_type($channel),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }

    /** @return list<mixed> */
    protected static function recipients(mixed $notifiables): array
    {
        if ($notifiables instanceof EloquentCollection || $notifiables instanceof Collection) {
            return $notifiables->values()->all();
        }

        return is_array($notifiables) ? array_values($notifiables) : [$notifiables];
    }
}
