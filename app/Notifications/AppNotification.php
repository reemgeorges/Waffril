<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * WAFFRLI (2026-11-03) — base for customer and driver notifications:
 *
 *   database   the in-app notification list (always)
 *   broadcast  live delivery on the recipient's private Reverb channel
 *              (user.{id} / driver.{id}) for clients that are online
 *   push       FCM to the recipient's active devices (PushChannel; skipped
 *              when the channel is occupied, disabled unless configured)
 *
 * Payloads carry an event name and identifiers only (order id / number,
 * request id) plus generic bilingual text: never addresses, phone numbers,
 * amounts, codes or notes. Clients fetch details over the authenticated API.
 */
abstract class AppNotification extends Notification
{
    /** e.g. order.accepted */
    abstract public function event(): string;

    /** @return array{title: array{en: string, ar: string}, body: array{en: string, ar: string}} */
    abstract protected function texts(): array;

    /** Identifiers only. @return array<string, int|string|null> */
    abstract protected function references(): array;

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', PushChannel::class];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event();
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }

    public function toArray(object $notifiable): array
    {
        $texts = $this->texts();

        return ['event' => $this->event(), 'title' => $texts['title'], 'message' => $texts['body']] + $this->references();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $locale = $this->readerLocale($notifiable);
        $texts = $this->texts();

        return new BroadcastMessage([
            'event' => $this->event(),
            'title' => $texts['title'][$locale],
            'message' => $texts['body'][$locale],
        ] + $this->references());
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        $locale = $this->readerLocale($notifiable);
        $texts = $this->texts();
        $data = ['event' => $this->event(), 'notification_id' => (string) $this->id];
        foreach ($this->references() as $key => $value) {
            if ($value !== null) {
                $data[$key] = (string) $value;
            }
        }

        return ['title' => $texts['title'][$locale], 'body' => $texts['body'][$locale], 'data' => $data];
    }

    /** The same translation in every supported locale: {ar: ..., en: ...}. */
    protected function both(string $key, array $replace = []): array
    {
        return ['ar' => __($key, $replace, 'ar'), 'en' => __($key, $replace, 'en')];
    }

    protected function readerLocale(object $notifiable): string
    {
        return in_array($notifiable->language ?? null, ['ar', 'en'], true) ? $notifiable->language : 'ar';
    }
}
