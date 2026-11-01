<?php

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — the `recording` broadcast connection for automated
 * tests. Channel authorization (signatures included) is exactly Pusher's /
 * Reverb's, but published events are kept in memory instead of being sent,
 * so tests can assert WHO would receive WHAT without a running Reverb
 * server. It refuses to run in production.
 */
class RecordingBroadcaster extends PusherBroadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public static array $sent = [];

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The recording broadcaster is for automated tests only.');
        }

        static::$sent[] = [
            'channels' => array_values(array_map('strval', $this->formatChannels($channels))),
            'event' => (string) $event,
            'payload' => Arr::except($payload, ['socket']),
        ];
    }

    /** @return list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public static function on(string $channel, ?string $event = null): array
    {
        return array_values(array_filter(static::$sent, fn ($m) => in_array($channel, $m['channels'], true)
            && ($event === null || $m['event'] === $event)));
    }

    public static function reset(): void
    {
        static::$sent = [];
    }
}
