<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * WAFFRLI (2026-10-31) — a business rule refused an operation (e.g. an
 * illegal order transition, a refund above the collectable amount). The
 * message is safe to show to admins/API clients. GeneralTrait renders it
 * in the API envelope with getStatus(); Filament actions show it as a
 * danger notification.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
