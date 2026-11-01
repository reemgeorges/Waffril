<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\TextMessageGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — development/test transport. Nothing leaves the
 * server: the last message per phone is kept ENCRYPTED in the cache for
 * 15 minutes so tests (and the local-only OTP peek endpoint / command) can
 * read it. Refused in production.
 */
class FakeTextGateway implements TextMessageGateway
{
    /** Phones for which delivery must fail (tests of the WhatsApp fallback). */
    public static array $failSmsFor = [];

    public function send(string $channel, string $phone, string $message): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The fake text transport is not available in production. Configure WAFFRLI_SMS_DRIVER.');
        }
        if ($channel === self::CHANNEL_SMS && in_array($phone, static::$failSmsFor, true)) {
            throw new RuntimeException('Simulated SMS failure.');
        }

        Cache::put(self::key($phone), Crypt::encryptString(json_encode(['channel' => $channel, 'message' => $message, 'at' => now()->toIso8601String()])), now()->addMinutes(15));
        Log::info('[messaging] fake transport: message stored (content withheld)', ['channel' => $channel]);
    }

    public function name(): string
    {
        return 'fake';
    }

    /** @return array{channel: string, message: string, at: string}|null */
    public static function last(string $phone): ?array
    {
        if (app()->environment('production')) {
            return null;
        }
        $value = Cache::get(self::key($phone));

        return $value ? json_decode(Crypt::decryptString($value), true) : null;
    }

    protected static function key(string $phone): string
    {
        return 'fake-text:'.hash('sha256', $phone);
    }
}
