<?php

namespace App\Http\Resources\Model\Driver;

use App\Http\Resources\Basic\BasicResource;
use Illuminate\Http\Request;

/** WAFFRLI driver API (2026-11-02) — one of this driver's daily cash settlements (read-only). */
class DriverSettlementResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'business_date' => $s->business_date?->toDateString(),
            'currency' => $s->currency?->code,
            'expected_amount' => (string) $s->expected_amount,
            'received_amount' => $s->received_amount !== null ? (string) $s->received_amount : null,
            'status' => $s->status,
            'settled_at' => $s->settled_at?->toIso8601String(),
            'note' => $s->note,
        ];
    }
}
