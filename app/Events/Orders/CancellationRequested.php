<?php

namespace App\Events\Orders;

use Illuminate\Foundation\Events\Dispatchable;

/** WAFFRLI (2026-11-03) — a customer asked to cancel an accepted order. */
class CancellationRequested
{
    use Dispatchable;

    public function __construct(public int $requestId) {}
}
