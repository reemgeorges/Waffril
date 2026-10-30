<?php

namespace App\Events\Plus;

use Illuminate\Foundation\Events\Dispatchable;

/** WAFFRLI (2026-11-03) — a customer submitted a PLUS request (needs admin review). */
class PlusRequestSubmitted
{
    use Dispatchable;

    public function __construct(public int $requestId) {}
}
