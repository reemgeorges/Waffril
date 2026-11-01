<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\TextMessageGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — SMS and WhatsApp through the Twilio Messages REST
 * API. Credentials come only from the environment (TWILIO_*). Not exercised
 * against the real provider in this repository: tests use Http::fake().
 */
class TwilioTextGateway implements TextMessageGateway
{
    public function send(string $channel, string $phone, string $message): void
    {
        $config = config('waffrli.messaging.twilio');
        $from = $channel === self::CHANNEL_WHATSAPP ? $config['whatsapp_from'] : $config['sms_from'];
        if (! $config['account_sid'] || ! $config['auth_token'] || ! $from) {
            throw new RuntimeException("Twilio {$channel} is not configured.");
        }

        $prefix = $channel === self::CHANNEL_WHATSAPP ? 'whatsapp:' : '';
        $response = Http::asForm()
            ->withBasicAuth($config['account_sid'], $config['auth_token'])
            ->timeout((int) $config['timeout'])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages.json", [
                'To' => $prefix.$phone,
                'From' => $prefix.$from,
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            // Never include the body (OTP) in the error.
            throw new RuntimeException("Twilio {$channel} delivery failed with HTTP {$response->status()}.");
        }
    }

    public function name(): string
    {
        return 'twilio';
    }
}
