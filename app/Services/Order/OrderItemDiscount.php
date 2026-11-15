<?php

namespace App\Services\Order;

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * WAFFRLI (2026-11-15) — what kind of offer produced an order line's
 * discount, for display (receipts).
 *
 * An order item snapshots the offer id and the exact discount amount, but
 * NOT the offer's type or percentage; those live on the (editable) offer.
 * So the offer's CURRENT definition is reported only when it reproduces the
 * stored discount EXACTLY with the same formula as checkout
 * (OrderPricingService::bestOffer) and the order's own frozen values (line
 * subtotal, currency decimals, frozen exchange rate). Nothing is derived
 * from amounts: the percentage shown is the offer's own, verified.
 *
 * Otherwise (no offer, the offer was edited since, a fixed offer whose base
 * currency changed, …) null is returned and clients show the monetary
 * discount only. Stored values are never modified.
 */
final class OrderItemDiscount
{
    /** @return array{type: 'percent'|'fixed', percent: ?string}|null */
    public static function verifiedOffer(OrderItem $item, Order $order): ?array
    {
        $stored = BigDecimal::of((string) $item->offer_discount_amount);
        $offer = $item->offer;
        if ($stored->isZero() || $offer === null || $order->currency === null) {
            return null;
        }
        $decimals = (int) $order->currency->decimals;
        $subtotal = BigDecimal::of((string) $item->line_subtotal);

        if ($offer->discount_type === 'percent') {
            $expected = Money::roundTo($subtotal->multipliedBy((string) $offer->discount_value)->dividedBy(100, 8, RoundingMode::HALF_UP), $decimals);
        } elseif ($offer->discount_type === 'fixed') {
            // Fixed offers are stored in the accounting base: only comparable
            // while the order's base is still the default (frozen rate applies).
            if ((int) $order->base_currency_id !== (int) Currency::query()->default()->value('id')) {
                return null;
            }
            $expected = Money::roundTo(BigDecimal::of((string) $offer->discount_value)->multipliedBy((string) $order->exchange_rate), $decimals)
                ->multipliedBy((int) $item->quantity);
        } else {
            return null;
        }
        if ($expected->isGreaterThan($subtotal)) {
            $expected = $subtotal;
        }
        if (! $expected->isEqualTo($stored)) {
            return null;
        }

        return $offer->discount_type === 'percent'
            ? ['type' => 'percent', 'percent' => (string) BigDecimal::of((string) $offer->discount_value)->stripTrailingZeros()]
            : ['type' => 'fixed', 'percent' => null];
    }
}
