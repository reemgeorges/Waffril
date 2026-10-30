<?php

namespace App\Services\Push;

use App\Contracts\Push\PushTransport;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — in-memory push transport for tests and local
 * development. Nothing leaves the process; refused in production so it can
 * never hide a missing FCM configuration there.
 */
class FakePushTransport implements PushTransport
{
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
        if (in_array($token, static::$invalid, true)) {
            return self::RESULT_INVALID_TOKEN;
        }
        static::$sent[] = ['token' => $token, 'message' => $message];

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
