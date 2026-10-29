<?php

namespace App\Services\Auth\Otp\Channels;

use App\Contracts\Auth\OtpChannelProvider;
use App\Mail\OtpMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 3A (2026-10-25). Uses Laravel's own configured mailer (Mail facade)
 * — truthful about what it does: with MAIL_MAILER=log (this project's
 * .env default) it writes to the log file, nothing more. No real transport
 * (SMTP/SES/Postmark/etc.) credentials are invented or assumed.
 */
class EmailOtpChannel implements OtpChannelProvider
{
    public function deliver(Model $subject, string $purpose, string $code): void
    {
        $email = $subject->email ?? null;

        if (! $email) {
            throw new \RuntimeException('EmailOtpChannel: subject has no email address.');
        }

        Mail::to($email)->send(new OtpMail($code, $purpose, $subject->name ?? null));
    }

    public function channel(): string
    {
        return 'email';
    }
}
