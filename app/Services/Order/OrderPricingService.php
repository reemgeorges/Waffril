<?php

namespace App\Services\Order;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Currency;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PlusCycle;
use App\Models\PlusCycleDiscount;
use App\Models\ProductOption;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Functional\Delivery\DeliveryFeeResolver;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * WAFFRLI (2026-11-03) — THE pricing engine (checkout, cart previews and
 * demo seeding all use it). Read-only: it validates and prices, it never
 * consumes benefits or moves money.
 *
 * Rules:
 *  - Catalog prices are in the DEFAULT currency; every unit price is
 *    converted to the order currency with the admin-configured rate and
 *    rounded to that currency's decimals, then multiplied by quantity.
 *  - Options must belong to the product, be active, available at the
 *    branch, and respect each group's min/max selection and the option's
 *    max quantity.
 *  - A PLUS store discount (from the member's active cycle SNAPSHOT) takes
 *    priority over public offers: when it applies, product offers are not
 *    applied to that order. Free delivery may combine with it.
 *  - Delivery fee: the platform-wide quote for branch → address.
 *  - Nothing submitted by a client (prices, totals) is trusted.
 */
class OrderPricingService
{
    public function __construct(protected DeliveryFeeResolver $fees) {}

    /**
     * @param  list<array{branch_product_id: int, quantity: int, options?: array<int|string, int>, notes?: ?string}>  $lines  options: product_option_id => quantity
     * @return array<string, mixed>
     */
    public function price(User $user, Branch $branch, array $lines, Currency $currency, ?UserAddress $address, bool $wantFreeDelivery): array
    {
        if ($lines === []) {
            throw new BusinessRuleException(__('messages.customer.cart_empty'), 422);
        }

        $default = Currency::query()->default()->firstOrFail();
        $rate = (string) $currency->exchange_rate;
        $convert = fn ($amount) => Money::roundTo(BigDecimal::of((string) $amount)->multipliedBy($rate), $currency->decimals);

        $branchProducts = BranchProduct::query()->where('branch_id', $branch->id)
            ->whereIn('id', array_column($lines, 'branch_product_id'))
            ->with(['product.optionGroups.options', 'options'])->get()->keyBy('id');

        $items = [];
        $subtotal = BigDecimal::zero();
        foreach ($lines as $line) {
            $bp = $branchProducts->get($line['branch_product_id']);
            $product = $bp?->product;
            if (! $bp || ! $bp->is_available || ! $product || ! $product->is_active || $product->trashed()) {
                throw new BusinessRuleException(__('messages.customer.item_unavailable'), 422);
            }
            $quantity = (int) $line['quantity'];
            if ($quantity < 1 || $quantity > 99) {
                throw new BusinessRuleException(__('validation.between.numeric', ['attribute' => 'quantity', 'min' => 1, 'max' => 99]), 422);
            }

            $options = $this->resolveOptions($bp, $line['options'] ?? []);
            $optionsUnit = BigDecimal::zero();
            $optionRows = [];
            foreach ($options as [$option, $branchOption, $qty]) {
                $unitDelta = $convert((string) $branchOption->price_delta);
                $total = $unitDelta->multipliedBy($qty);
                $optionsUnit = $optionsUnit->plus($total);
                $optionRows[] = [
                    'product_option_id' => $option->id,
                    'group_name' => $option->group->getTranslations('name'),
                    'option_name' => $option->getTranslations('name'),
                    'quantity' => $qty,
                    'unit_price_delta' => Money::toStorage($unitDelta),
                    'total_price_delta' => Money::toStorage($total),
                ];
            }

            $base = $convert((string) $bp->price);
            $unit = $base->plus($optionsUnit);
            $lineSubtotal = $unit->multipliedBy($quantity);
            $subtotal = $subtotal->plus($lineSubtotal);

            $items[] = [
                'product_id' => $product->id, 'branch_product_id' => $bp->id,
                'product_name' => $product->getTranslations('name'), 'product_image' => $product->image,
                'base_unit_price' => $base, 'options_unit_price' => $optionsUnit, 'unit_price' => $unit,
                'quantity' => $quantity, 'line_subtotal' => $lineSubtotal,
                'offer_id' => null, 'offer_discount_amount' => BigDecimal::zero(), 'line_total' => $lineSubtotal,
                'notes' => $line['notes'] ?? null, 'options' => $optionRows,
            ];
        }

        // PLUS: active cycle snapshot discount for this store.
        $cycle = PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
        [$cycleDiscount, $plusDiscount] = $this->plusDiscount($cycle, $branch->store_id, $subtotal, $convert, $currency->decimals);

        // Public offers only when no PLUS discount applies (PLUS has priority).
        $offerTotal = BigDecimal::zero();
        if ($plusDiscount->isZero()) {
            $offers = Offer::query()->currentlyActive()->where('store_id', $branch->store_id)->whereNotNull('product_id')
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))->get()->groupBy('product_id');
            foreach ($items as &$item) {
                [$offer, $discount] = $this->bestOffer($offers->get($item['product_id'], collect()), $item['line_subtotal'], $item['quantity'], $convert, $currency->decimals);
                $item['offer_id'] = $offer?->id;
                $item['offer_discount_amount'] = $discount;
                $item['line_total'] = $item['line_subtotal']->minus($discount);
                $offerTotal = $offerTotal->plus($discount);
            }
            unset($item);
        }

        // Delivery.
        $quote = $address ? $this->fees->quote($branch->city_id, (float) $branch->lat, (float) $branch->lng, (float) $address->lat, (float) $address->lng) : null;
        $feeOriginal = $quote && $quote->covered ? $convert($quote->fee) : null;
        $freeDeliveryAvailable = $cycle !== null && $cycle->free_deliveries_used < $cycle->free_deliveries_total;
        $free = $wantFreeDelivery && $freeDeliveryAvailable && $feeOriginal !== null;
        $fee = $free ? BigDecimal::zero() : ($feeOriginal ?? BigDecimal::zero());

        $total = $subtotal->minus($offerTotal)->minus($plusDiscount)->plus($fee);
        $minOrder = $branch->min_order_amount !== null ? $convert((string) $branch->min_order_amount) : null;

        return [
            'currency' => $currency, 'default_currency' => $default, 'exchange_rate' => $rate,
            'items' => $items, 'subtotal' => $subtotal, 'offer_discount_total' => $offerTotal,
            'plus_cycle' => $cycle, 'plus_cycle_discount' => $cycleDiscount, 'plus_discount_amount' => $plusDiscount,
            'quote' => $quote, 'delivery_covered' => $quote?->covered ?? false,
            'delivery_fee_original' => $feeOriginal ?? BigDecimal::zero(), 'delivery_fee' => $fee,
            'free_delivery_available' => $freeDeliveryAvailable, 'free_delivery_applied' => $free,
            'total' => $total, 'min_order_amount' => $minOrder,
            'meets_min_order' => $minOrder === null || $subtotal->minus($offerTotal)->minus($plusDiscount)->isGreaterThanOrEqualTo($minOrder),
        ];
    }

    /**
     * @param  array<int|string, int>  $selected  product_option_id => quantity
     * @return list<array{0: ProductOption, 1: BranchProductOption, 2: int}>
     */
    public function resolveOptions(BranchProduct $bp, array $selected): array
    {
        $product = $bp->product;
        $groups = $product->optionGroups->where('is_active', true)->filter(fn ($g) => ! $g->trashed());
        $branchOptions = $bp->options->keyBy('product_option_id');
        $resolved = [];

        foreach ($selected as $optionId => $qty) {
            $qty = (int) $qty;
            $option = $groups->flatMap(fn ($g) => $g->options)->firstWhere('id', (int) $optionId);
            $branchOption = $branchOptions->get((int) $optionId);
            if (! $option || ! $option->is_active || $option->trashed() || ! $branchOption || ! $branchOption->is_available
                || $qty < 1 || $qty > max(1, (int) $option->max_quantity)) {
                throw new BusinessRuleException(__('messages.customer.option_invalid'), 422);
            }
            $resolved[] = [$option->setRelation('group', $groups->firstWhere('id', $option->option_group_id)), $branchOption, $qty];
        }

        foreach ($groups as $group) {
            $count = collect($resolved)->filter(fn ($r) => $r[0]->option_group_id === $group->id)->count();
            if ($count < $group->min_select || $count > $group->max_select) {
                throw new BusinessRuleException(__('messages.customer.option_selection_rule', [
                    'min' => $group->min_select, 'max' => $group->max_select, 'group' => $group->name,
                ]), 422);
            }
        }

        return $resolved;
    }

    /** Stable hash of a cart line configuration (same product + same options → same line). */
    public static function configurationHash(int $branchProductId, array $options): string
    {
        $normalized = collect($options)->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])->sortKeys()->all();

        return hash('sha256', $branchProductId.'|'.json_encode($normalized));
    }

    protected function bestOffer(Collection $offers, BigDecimal $lineSubtotal, int $quantity, callable $convert, int $decimals): array
    {
        $best = [null, BigDecimal::zero()];
        foreach ($offers as $offer) {
            $discount = $offer->discount_type === 'percent'
                ? Money::roundTo($lineSubtotal->multipliedBy((string) $offer->discount_value)->dividedBy(100, 8, RoundingMode::HALF_UP), $decimals)
                : $convert((string) $offer->discount_value)->multipliedBy($quantity);
            $discount = $discount->isGreaterThan($lineSubtotal) ? $lineSubtotal : $discount;
            if ($discount->isGreaterThan($best[1])) {
                $best = [$offer, $discount];
            }
        }

        return $best;
    }

    protected function plusDiscount(?PlusCycle $cycle, int $storeId, BigDecimal $base, callable $convert, int $decimals): array
    {
        $row = $cycle ? PlusCycleDiscount::query()->where('plus_cycle_id', $cycle->id)->where('store_id', $storeId)->first() : null;
        if (! $row) {
            return [null, BigDecimal::zero()];
        }
        if ($row->op_min_order_amount !== null && $base->isLessThan($convert((string) $row->op_min_order_amount))) {
            return [null, BigDecimal::zero()];
        }
        if ($row->max_uses_per_cycle !== null
            && Order::query()->where('plus_cycle_discount_id', $row->id)->where('status', '!=', 'cancelled')->count() >= $row->max_uses_per_cycle) {
            return [null, BigDecimal::zero()];
        }

        $discount = $row->discount_type === 'percent'
            ? Money::roundTo($base->multipliedBy((string) $row->op_discount_value)->dividedBy(100, 8, RoundingMode::HALF_UP), $decimals)
            : $convert((string) $row->op_discount_value);
        if ($row->op_max_discount_amount !== null && $discount->isGreaterThan($convert((string) $row->op_max_discount_amount))) {
            $discount = $convert((string) $row->op_max_discount_amount);
        }

        return [$row, $discount->isGreaterThan($base) ? $base : $discount];
    }
}
