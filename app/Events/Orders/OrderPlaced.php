<?php

namespace App\Events\Orders;

use Illuminate\Foundation\Events\Dispatchable;

/** WAFFRLI (2026-11-03) — a customer submitted an order (dispatched after commit). */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
