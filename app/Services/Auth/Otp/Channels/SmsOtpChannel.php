<?php

namespace App\Services\Auth\Otp\Channels;

use App\Contracts\Auth\OtpChannelProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3A (2026-10-25). No SMS gateway credentials exist anywhere in this
 * project (checked .env and every config/*.php — nothing). Per explicit
 * instruction not to invent credentials or claim external delivery works,
 * this adapter satisfies the OtpChannelProvider contract shape but refuses
 * to pretend a code was actually sent: it throws, loudly and specifically,
 * rather than silently no-opping (which would let a caller believe a user
 * received a code that was never sent).
 *
 * To make this real: implement deliver() against an actual SMS gateway
 * (e.g. Twilio, Vonage) using real, environment-provided credentials, and
 * update config('otp.channels.sms') if you rename this class.
 */
class SmsOtpChannel implements OtpChannelProvider
{
    public function deliver(Model $subject, string $purpose, string $code): void
    {
        throw new \RuntimeException(
            'SMS OTP delivery is not implemented in this starter kit (no SMS gateway is configured). '.
            'Implement App\Services\Auth\Otp\Channels\SmsOtpChannel against a real provider before using channel "sms".'
        );
    }

    public function channel(): string
    {
        return 'sms';
    }
}
