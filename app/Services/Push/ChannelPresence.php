<?php

namespace App\Services\Push;

use App\Support\Broadcasting\RecordingBroadcaster;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — is someone connected to a private Reverb channel
 * right now? Used to skip a push when the app is open (it already got the
 * broadcast). Any doubt (no Reverb, API error) answers "no", so the
 * recipient still gets the push.
 */
class ChannelPresence
{
    /** Channels the recording broadcaster reports as occupied (tests). @var list<string> */
    public static array $occupiedForTesting = [];

    public function isOccupied(string $channel): bool
    {
        try {
            $broadcaster = Broadcast::connection();
            if ($broadcaster instanceof RecordingBroadcaster) {
                return in_array($channel, static::$occupiedForTesting, true);
            }
            if (! $broadcaster instanceof PusherBroadcaster) {
                return false;
            }

            return (bool) ($broadcaster->getPusher()->getChannelInfo($channel)->occupied ?? false);
        } catch (Throwable $e) {
            Log::info('push.presence_unknown', ['channel' => $channel, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
