<?php

namespace App\Http\Resources\Model\Driver;

use App\Enums\DeliveryOfferStatus;
use App\Models\DeliveryOffer;
use App\Services\Driver\DriverDeliveryService;
use App\Support\Localization\Label;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — a delivery offer as the offered
 * driver sees it, in the REST API and in the Reverb `offer.*` events.
 *
 * Deliberately limited: the store, the pickup branch, the delivery CITY
 * and AREA, the distance, an item count and the cash to collect. Never the
 * customer's name, phone, address, delivery coordinates or notes (those
 * are returned by GET /driver/orders/{id} only once the driver has
 * accepted and the order is theirs). Times are server time; clients count
 * down with `expires_at` against `server_now`.
 *
 * @mixin DeliveryOffer
 */
class DriverOfferResource extends JsonResource
{
    /** Relations the resource reads (eager-load them). */
    public const WITH = ['order.branch.store', 'order.city', 'order.deliveryArea', 'order.currency', 'order.items', 'order.payments.method'];

    public function toArray(Request $request): array
    {
        /** @var DeliveryOffer $offer */
        $offer = $this->resource;
        $order = $offer->order;
        $currency = $order->currency;
        $format = fn ($amount) => (string) Money::roundTo((string) $amount, $currency->decimals);
        $now = now();
        $open = $offer->status === DeliveryOfferStatus::Pending && $now->lessThan($offer->expires_at);

        return [
            'id' => $offer->id,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => $order->type,
                'type_label' => Label::of('order_type', $order->type),
                'scheduled_for' => $order->scheduled_for?->toIso8601String(),
                'items_count' => (int) $order->items->sum('quantity'),
                'lines_count' => $order->items->count(),
            ],
            'store' => [
                'id' => $order->branch?->store?->id,
                'name' => $order->branch?->store?->name,
            ],
            'pickup' => [
                'branch_id' => $order->branch?->id,
                'name' => $order->branch?->name,
                'address' => $order->branch?->address,
                'lat' => $order->branch ? (float) $order->branch->lat : null,
                'lng' => $order->branch ? (float) $order->branch->lng : null,
            ],
            'delivery' => [
                'city' => $order->city?->name,
                'area' => $order->deliveryArea?->name,
                'distance_km' => (float) $order->delivery_distance_km,
            ],
            'payment' => [
                'currency' => ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimals' => $currency->decimals],
                'order_total' => $format($order->total),
                'prepaid_amount' => $format(DriverDeliveryService::prepaid($order)),
                'cash_to_collect' => $format(DriverDeliveryService::cashDue($order)),
            ],
            'offered_at' => $offer->offered_at->toIso8601String(),
            'expires_at' => $offer->expires_at->toIso8601String(),
            'server_now' => $now->toIso8601String(),
            'remaining_seconds' => $open ? (int) $now->diffInSeconds($offer->expires_at, true) : 0,
            'responded_at' => $offer->responded_at?->toIso8601String(),
            'reject_reason' => $offer->reject_reason,
            'allowed_actions' => $open ? ['accept', 'reject'] : [],
        ];
    }
}
