<?php

namespace App\Http\Resources\Model\Driver;

use App\Http\Resources\Basic\BasicResource;
use App\Support\Localization\Label;
use App\Support\Money\Denomination;
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
            'expected_amount' => Denomination::presentString((string) $s->expected_amount, $s->currency),
            'received_amount' => Denomination::presentString($s->received_amount !== null ? (string) $s->received_amount : null, $s->currency),
            'status' => $s->status,
            'status_label' => Label::of('settlement_status', $s->status),
            'settled_at' => $s->settled_at?->toIso8601String(),
            'note' => $s->note,
        ];
    }
}
