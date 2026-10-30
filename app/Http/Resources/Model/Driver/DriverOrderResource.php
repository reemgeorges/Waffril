<?php

namespace App\Http\Resources\Model\Driver;

use App\Enums\OrderStatus;
use App\Http\Resources\Basic\BasicResource;
use App\Models\Order;
use App\Services\Driver\DriverDeliveryService;
use App\Support\Localization\Label;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/**
 * WAFFRLI driver API (2026-11-02) — an order as its driver sees it.
 *
 * Customer contact data (name, phone, full address details) is included
 * ONLY while the delivery is active (assigned / picked up). Historical
 * orders keep only what the driver needs for their own records: no
 * customer name or phone, no address details.
 *
 * Amounts are read-only snapshots in the order's frozen currency; nothing
 * here can change them.
 */
class DriverOrderResource extends BasicResource
{
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $active = in_array($order->status, [OrderStatus::Assigned, OrderStatus::PickedUp], true);
        $currency = $order->currency;
        $format = fn ($amount) => (string) Money::roundTo((string) $amount, $currency->decimals);
        $address = (array) $order->delivery_address;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'type' => $order->type,
            'type_label' => Label::of('order_type', $order->type),
            'scheduled_for' => $order->scheduled_for?->toIso8601String(),
            'store' => [
                'id' => $order->branch?->store?->id,
                'name' => $order->branch?->store?->name,
            ],
            'branch' => [
                'id' => $order->branch?->id,
                'name' => $order->branch?->name,
                'address' => $order->branch?->address,
                'phone' => $active ? $order->branch?->phone : null,
                'lat' => $order->branch ? (float) $order->branch->lat : null,
                'lng' => $order->branch ? (float) $order->branch->lng : null,
            ],
            'delivery' => [
                'city' => $order->city?->name,
                'area' => $order->deliveryArea?->name,
                'address' => $active ? ($address['line'] ?? null) : null,
                'address_details' => $active ? ($address['details'] ?? null) : null,
                'address_label' => $active ? ($address['label'] ?? null) : null,
                'lat' => $active ? (float) $order->delivery_lat : null,
                'lng' => $active ? (float) $order->delivery_lng : null,
                'distance_km' => (float) $order->delivery_distance_km,
                'instructions' => $active ? $order->customer_notes : null,
            ],
            'customer' => $active ? [
                'name' => $order->user?->fullName(),
                'phone' => $order->user?->phone,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name[app()->getLocale()] ?? collect($item->product_name)->first(),
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'options' => $item->options->map(fn ($option) => [
                    'group' => $option->group_name[app()->getLocale()] ?? collect($option->group_name)->first(),
                    'option' => $option->option_name[app()->getLocale()] ?? collect($option->option_name)->first(),
                    'quantity' => $option->quantity,
                ])->values(),
            ])->values(),
            'payment' => [
                'currency' => ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimals' => $currency->decimals],
                'order_total' => $format($order->total),
                'prepaid_amount' => $format(DriverDeliveryService::prepaid($order)),
                'cash_to_collect' => $format(DriverDeliveryService::cashDue($order)),
                'cash_collected' => $format($order->payments
                    ->filter(fn ($p) => $p->method?->kind === 'cash' && $p->collected_by_driver_id === $order->driver_id)
                    ->reduce(fn ($carry, $p) => $carry->plus((string) $p->collected_amount), BigDecimal::zero())),
            ],
            'timeline' => [
                'created_at' => $order->created_at?->toIso8601String(),
                'accepted_at' => $order->accepted_at?->toIso8601String(),
                'assigned_at' => $order->assigned_at?->toIso8601String(),
                'picked_up_at' => $order->picked_up_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ],
            'has_delivery_photo' => (bool) $order->delivery_photo_path,
            'allowed_actions' => array_values(array_filter([
                $order->status === OrderStatus::Assigned ? 'pickup' : null,
                $order->status === OrderStatus::PickedUp ? 'deliver' : null,
                $active ? 'report_problem' : null,
            ])),
        ];
    }
}
