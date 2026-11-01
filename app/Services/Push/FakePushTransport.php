<?php

namespace App\Services\Push;

use App\Contracts\Push\PushTransport;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — in-memory push transport for tests and local
 * development. Nothing leaves the process; refused in production so it can
 * never hide a missing FCM configuration there.
 *
 * Cross-process observability (2026-11-05, Docker E2E): the queue worker is a
 * separate process, so the fake also
 *  - reports tokens starting with UNREGISTERED_PREFIX as unregistered (the
 *    job then deactivates them, exactly like an FCM 404/UNREGISTERED), and
 *  - logs each simulated delivery as `push.fake_delivered` with a token
 *    HASH (never the token) and the message title.
 * Nothing is ever sent to Firebase.
 */
class FakePushTransport implements PushTransport
{
    public const UNREGISTERED_PREFIX = 'fake-unregistered-';

    /** @var list<array{token: string, message: array}> */
    public static array $sent = [];

    /** Tokens the fake reports as unregistered. @var list<string> */
    public static array $invalid = [];

    /** Tokens that fail transiently (exception) once each. @var list<string> */
    public static array $failOnce = [];

    public function send(string $token, array $message): string
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The fake push transport cannot run in production.');
        }
        if (in_array($token, static::$failOnce, true)) {
            static::$failOnce = array_values(array_diff(static::$failOnce, [$token]));
            throw new RuntimeException('Simulated transient push failure.');
        }
        if (in_array($token, static::$invalid, true) || str_starts_with($token, self::UNREGISTERED_PREFIX)) {
            return self::RESULT_INVALID_TOKEN;
        }
        static::$sent[] = ['token' => $token, 'message' => $message];
        Log::info('push.fake_delivered', [
            'token_sha256' => substr(hash('sha256', $token), 0, 16),
            'title' => $message['notification']['title'] ?? $message['title'] ?? null,
        ]);

        return self::RESULT_SENT;
    }

    public function name(): string
    {
        return 'fake';
    }

    public static function reset(): void
    {
        static::$sent = [];
        static::$invalid = [];
        static::$failOnce = [];
    }

    /** @return list<array{token: string, message: array}> */
    public static function to(string $token): array
    {
        return array_values(array_filter(static::$sent, fn ($m) => $m['token'] === $token));
    }
}
