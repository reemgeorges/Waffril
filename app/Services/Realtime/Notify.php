<?php

namespace App\Services\Realtime;

use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — sends notifications after the surrounding
 * transaction commits; a broadcast/push problem is logged and never fails
 * the order, payment or PLUS operation that caused it.
 */
class Notify
{
    public static function send(mixed $notifiables, BaseNotification $notification): void
    {
        if (config('waffrli.notifications_muted')) {
            return; // demo seeding
        }
        DB::afterCommit(function () use ($notifiables, $notification) {
            try {
                Notification::send($notifiables, $notification);
            } catch (Throwable $e) {
                Log::warning('notify.failed', ['notification' => $notification::class, 'error' => $e->getMessage()]);
            }
        });
    }
}
