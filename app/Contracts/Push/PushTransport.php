<?php

namespace App\Contracts\Push;

/**
 * WAFFRLI (2026-11-03) — sends ONE push message to ONE device token.
 *
 * Returns one of the RESULT_* constants; transient failures throw so the
 * queued job retries them.
 */
interface PushTransport
{
    public const RESULT_SENT = 'sent';

    /** The token is unknown / expired / for another app: deactivate it. */
    public const RESULT_INVALID_TOKEN = 'invalid_token';

    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $message
     */
    public function send(string $token, array $message): string;

    public function name(): string;
}
