<?php

namespace App\Contracts\Messaging;

/**
 * WAFFRLI (2026-11-03) — sends a short text to an E.164 phone number over
 * SMS or WhatsApp. Implementations throw on failure; they never log the
 * message body (it may contain an OTP).
 */
interface TextMessageGateway
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public function send(string $channel, string $phone, string $message): void;

    public function name(): string;
}
