<?php

namespace App\Services\Auth\Otp\Channels;

use App\Contracts\Auth\OtpChannelProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3A (2026-10-25). Same reasoning as SmsOtpChannel: no WhatsApp
 * Business API credentials exist anywhere in this project. Throws rather
 * than silently pretending delivery succeeded.
 */
class WhatsAppOtpChannel implements OtpChannelProvider
{
    public function deliver(Model $subject, string $purpose, string $code): void
    {
        throw new \RuntimeException(
            'WhatsApp OTP delivery is not implemented in this starter kit (no WhatsApp Business API is configured). '.
            'Implement App\Services\Auth\Otp\Channels\WhatsAppOtpChannel against a real provider before using channel "whatsapp".'
        );
    }

    public function channel(): string
    {
        return 'whatsapp';
    }
}
