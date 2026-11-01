<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\TextMessageGateway;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * WAFFRLI (2026-11-03) — SMS first, optional WhatsApp fallback.
 */
class TextMessenger
{
    /** The gateway is resolved per delivery (config can change; controllers are cached per route). */
    protected function gateway(): TextMessageGateway
    {
        return app(TextMessageGateway::class);
    }

    public static function gatewayFor(string $driver): TextMessageGateway
    {
        return match ($driver) {
            'fake' => app(FakeTextGateway::class),
            'twilio' => app(TwilioTextGateway::class),
            default => throw new InvalidArgumentException("Unknown text messaging driver [{$driver}]."),
        };
    }

    /** @return string the channel that delivered */
    public function deliver(string $phone, string $message, string $primary = TextMessageGateway::CHANNEL_SMS): string
    {
        try {
            $this->gateway()->send($primary, $phone, $message);

            return $primary;
        } catch (Throwable $e) {
            $fallback = config('waffrli.customer.whatsapp_fallback') && $primary === TextMessageGateway::CHANNEL_SMS;
            Log::warning('[messaging] primary channel failed', ['channel' => $primary, 'fallback' => $fallback, 'error' => $e->getMessage()]);
            if (! $fallback) {
                throw $e;
            }
            $this->gateway()->send(TextMessageGateway::CHANNEL_WHATSAPP, $phone, $message);

            return TextMessageGateway::CHANNEL_WHATSAPP;
        }
    }
}
