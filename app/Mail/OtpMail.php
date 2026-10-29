<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3A (2026-10-25). Genuinely new — OtpChannelHelper referenced this
 * class before this phase, but it never existed, so every email-channel
 * send() call fataled with "Class App\Mail\OtpMail not found".
 *
 * Uses Laravel's actual configured mailer truthfully (MAIL_MAILER=log in
 * this project's .env, so "sending" writes to the log file) — this does
 * NOT claim or implement a real third-party email provider integration.
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your verification code',
        );
    }

    public function content(): Content
    {
        $greeting = $this->recipientName ? "Hello {$this->recipientName}," : 'Hello,';

        return new Content(
            htmlString: "<p>{$greeting}</p>".
                "<p>Your verification code is: <strong>{$this->code}</strong></p>".
                "<p>Purpose: {$this->purpose}. This code will expire soon and can only be used once.</p>".
                '<p>If you did not request this, you can safely ignore this email.</p>',
        );
    }
}
