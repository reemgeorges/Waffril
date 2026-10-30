<?php

namespace App\Http\Resources\Model\Driver;

use App\Http\Resources\Basic\BasicResource;
use App\Models\DriverCashSettlement;
use Illuminate\Http\Request;

/** WAFFRLI driver API (2026-11-02) — one cash payment this driver collected. */
class DriverCashCollectionResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $settlement = $payment->settlement;

        return [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_number' => $payment->order?->order_number,
            'currency' => $payment->currency?->code,
            'amount_collected' => (string) $payment->collected_amount,
            'collected_at' => $payment->paid_at?->toIso8601String(),
            'settlement' => $settlement ? [
                'id' => $settlement->id,
                'business_date' => $settlement->business_date?->toDateString(),
                'status' => $settlement->status,
            ] : null,
            'settled' => $settlement?->status === DriverCashSettlement::STATUS_SETTLED,
        ];
    }
}
