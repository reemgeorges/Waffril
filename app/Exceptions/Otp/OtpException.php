<?php

namespace App\Exceptions\Otp;

/**
 * Phase 3A (2026-10-25). Thrown by App\Services\Auth\OtpService for every
 * "cannot proceed" case (cooldown, rate limit exceeded, locked out, channel
 * not configured). Carries its own HTTP status so
 * App\Http\Traits\GeneralTrait::handleException() can render it in the
 * standard API envelope without needing a type-per-status-code hierarchy.
 */
class OtpException extends \RuntimeException
{
    public function __construct(string $message, protected int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
