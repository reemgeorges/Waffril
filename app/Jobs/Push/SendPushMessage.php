<?php

namespace App\Jobs\Push;

use App\Contracts\Push\PushTransport;
use App\Models\DeviceToken;
use App\Services\Push\ChannelPresence;
use App\Services\Push\DeviceTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — one push message to one device token.
 *
 * - Retried (config waffrli.push.tries, growing backoff) on transient errors.
 * - Deduplicated per (dedupe key, token): a retry after success or a
 *   repeated dispatch never pushes twice.
 * - Skipped while the recipient's private Reverb channel is occupied (the
 *   open app already received the broadcast) when skip_when_online is on.
 * - Unregistered tokens are deactivated. Logs carry the token row id only.
 */
class SendPushMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    /** @param  array{title: string, body: string, data: array<string, string>}  $message */
    public function __construct(
        public int $deviceTokenId,
        public array $message,
        public string $dedupeKey,
        public ?string $presenceChannel = null,
    ) {
        $this->tries = max(1, (int) config('waffrli.push.tries', 3));
        $this->onQueue(config('waffrli.push.queue', 'default'));
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(PushTransport $transport, ChannelPresence $presence, DeviceTokenService $tokens): string
    {
        $token = DeviceToken::query()->find($this->deviceTokenId);
        if (! $token || ! $token->is_active) {
            return 'skipped_inactive';
        }
        if ($this->presenceChannel && config('waffrli.push.skip_when_online', true) && $presence->isOccupied($this->presenceChannel)) {
            Log::info('push.skipped_online', ['token_id' => $token->id, 'key' => $this->dedupeKey]);

            return 'skipped_online';
        }

        $lock = 'waffrli:push:sent:'.hash('sha256', $this->dedupeKey.'|'.$token->id);
        if (! Cache::add($lock, 1, now()->addDay())) {
            return 'skipped_duplicate';
        }

        try {
            $result = $transport->send($token->token, $this->message);
        } catch (Throwable $e) {
            Cache::forget($lock); // let the retry send it
            Log::warning('push.failed', ['token_id' => $token->id, 'transport' => $transport->name(), 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            throw $e;
        }

        if ($result === PushTransport::RESULT_INVALID_TOKEN) {
            $tokens->deactivate($token, 'invalid_token');
            Log::info('push.token_invalidated', ['token_id' => $token->id, 'transport' => $transport->name()]);

            return $result;
        }
        DeviceToken::query()->whereKey($token->id)->update(['last_seen_at' => now()]);
        Log::info('push.sent', ['token_id' => $token->id, 'transport' => $transport->name(), 'key' => $this->dedupeKey]);

        return $result;
    }
}
