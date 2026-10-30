<?php

namespace Database\Seeders\Demo;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Currency;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderPayment;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\PlusCycle;
use App\Models\PlusCycleDiscount;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Services\Functional\Delivery\DeliveryFeeResolver;
use App\Services\Plus\PlusService;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WAFFRLI demo — builds a submitted order exactly as checkout will: priced
 * from the branch price list, product offers, the member's active PLUS
 * cycle snapshot (discount + free delivery, consumed atomically), the
 * platform delivery-fee quote, all snapshotted in the order currency, with
 * cash and/or wallet payments (wallet debited through WalletLedger only).
 *
 * Status changes after submission are NOT made here: DemoOrderSeeder moves
 * orders through OrderAdminService / the state machine.
 */
class DemoOrderBuilder
{
    public function __construct(
        protected DeliveryFeeResolver $fees,
        protected WalletLedger $ledger,
        protected PlusService $plus,
    ) {}

    /**
     * @param  list<array{0: string, 1: int, 2: list<string>}>  $lines  [product key, quantity, option keys]
     * @param  array{currency?: string, wallet?: string, free_delivery?: bool, scheduled_for?: ?\DateTimeInterface, note?: ?string, address?: int}  $options
     */
    public function submit(string $number, User $user, Branch $branch, string $storeSlug, array $lines, array $options = []): Order
    {
        return DB::transaction(function () use ($number, $user, $branch, $storeSlug, $lines, $options) {
            $default = Currency::query()->default()->firstOrFail();
            $currency = isset($options['currency']) ? Currency::query()->where('code', $options['currency'])->firstOrFail() : $default;
            $rate = (string) $currency->exchange_rate;
            $convert = fn (BigDecimal|string $amount) => Money::roundTo(BigDecimal::of((string) $amount)->multipliedBy($rate), $currency->decimals);

            $addresses = UserAddress::query()->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get();
            $address = $addresses[$options['address'] ?? 0] ?? $addresses->first();
            if (! $address) {
                throw new RuntimeException("Demo customer {$user->email} has no address.");
            }

            $quote = $this->fees->quote($branch->city_id, (float) $branch->lat, (float) $branch->lng, (float) $address->lat, (float) $address->lng);
            if (! $quote->covered) {
                throw new RuntimeException("Demo order {$number}: address not covered ({$quote->reason}).");
            }

            // Priced lines.
            $items = [];
            $subtotal = BigDecimal::zero();
            $offerTotal = BigDecimal::zero();
            foreach ($lines as [$productKey, $quantity, $optionKeys]) {
                $product = DemoCatalogSeeder::findProduct($storeSlug, $productKey);
                $branchProduct = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
                if (! $branchProduct->is_available) {
                    throw new RuntimeException("Demo order {$number}: {$productKey} is unavailable at this branch.");
                }

                $optionRows = [];
                $optionsUnit = BigDecimal::zero();
                foreach ($optionKeys as $optionKey) {
                    $optionEn = $this->optionName($optionKey);
                    $branchOption = BranchProductOption::query()->where('branch_product_id', $branchProduct->id)
                        ->whereHas('option', fn ($query) => $query->where('name->en', $optionEn))->with('option.group')->firstOrFail();
                    $delta = $convert((string) $branchOption->price_delta);
                    $optionsUnit = $optionsUnit->plus($delta);
                    $optionRows[] = [
                        'product_option_id' => $branchOption->product_option_id,
                        'group_name' => $branchOption->option->group->getTranslations('name'),
                        'option_name' => $branchOption->option->getTranslations('name'),
                        'quantity' => 1,
                        'unit_price_delta' => Money::toStorage($delta),
                        'total_price_delta' => Money::toStorage($delta),
                    ];
                }

                $base = $convert((string) $branchProduct->price);
                $unit = $base->plus($optionsUnit);
                $lineSubtotal = $unit->multipliedBy($quantity);
                [$offer, $discount] = $this->offerFor($product->id, $branch, $lineSubtotal, $quantity, $convert, $currency->decimals);

                $items[] = [
                    'product_id' => $product->id, 'branch_product_id' => $branchProduct->id,
                    'product_name' => $product->getTranslations('name'), 'product_image' => $product->image,
                    'base_unit_price' => Money::toStorage($base), 'options_unit_price' => Money::toStorage($optionsUnit),
                    'unit_price' => Money::toStorage($unit), 'quantity' => $quantity,
                    'line_subtotal' => Money::toStorage($lineSubtotal),
                    'offer_id' => $offer?->id, 'offer_discount_amount' => Money::toStorage($discount),
                    'line_total' => Money::toStorage($lineSubtotal->minus($discount)),
                    'options' => $optionRows,
                ];
                $subtotal = $subtotal->plus($lineSubtotal);
                $offerTotal = $offerTotal->plus($discount);
            }

            // PLUS: discount from the active cycle's snapshot for this store, and free delivery.
            $cycle = PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
            [$cycleDiscount, $plusDiscount] = $this->plusDiscount($cycle, $branch->store_id, $subtotal->minus($offerTotal), $convert, $currency->decimals);

            $feeOriginal = $convert($quote->fee);
            $free = ($options['free_delivery'] ?? false) && $cycle && $this->plus->consumeFreeDelivery($cycle);
            $fee = $free ? BigDecimal::zero() : $feeOriginal;
            $total = $subtotal->minus($offerTotal)->minus($plusDiscount)->plus($fee);

            $scheduledFor = $options['scheduled_for'] ?? null;
            $order = Order::create([
                'order_number' => $number,
                'user_id' => $user->id,
                'client_request_id' => $this->uuid('order:'.$number),
                'branch_id' => $branch->id,
                'city_id' => $branch->city_id,
                'delivery_area_id' => $quote->deliveryAreaId,
                'delivery_fee_rule_id' => $quote->deliveryFeeRuleId,
                'user_address_id' => $address->id,
                'delivery_address' => ['label' => $address->label, 'line' => $address->address_line, 'details' => $address->details],
                'delivery_lat' => $address->lat, 'delivery_lng' => $address->lng,
                'delivery_distance_km' => round($quote->distanceKm, 3), 'distance_method' => $quote->distanceMethod,
                'type' => $scheduledFor ? Order::TYPE_SCHEDULED : Order::TYPE_IMMEDIATE,
                'scheduled_for' => $scheduledFor,
                'status' => OrderStatus::Pending->value,
                'base_currency_id' => $default->id, 'currency_id' => $currency->id, 'exchange_rate' => $rate,
                'subtotal' => Money::toStorage($subtotal),
                'offer_discount_total' => Money::toStorage($offerTotal),
                'plus_discount_amount' => Money::toStorage($plusDiscount),
                'delivery_fee_original' => Money::toStorage($feeOriginal),
                'delivery_fee' => Money::toStorage($fee),
                'total' => Money::toStorage($total),
                'plus_cycle_id' => ($free || $cycleDiscount) ? $cycle->id : null,
                'plus_cycle_discount_id' => $cycleDiscount?->id,
                'free_delivery_applied' => $free,
                'customer_notes' => $options['note'] ?? null,
            ]);

            foreach ($items as $item) {
                $optionRows = $item['options'];
                unset($item['options']);
                $orderItem = OrderItem::create($item + ['order_id' => $order->id]);
                foreach ($optionRows as $row) {
                    OrderItemOption::create($row + ['order_item_id' => $orderItem->id]);
                }
            }

            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending->value,
                'actor_type' => 'customer', 'actor_id' => $user->id, 'note' => 'Demo order submitted',
            ]);

            $this->payments($order, $currency, $total, $options['wallet'] ?? null);

            return $order->fresh();
        });
    }

    /** Wallet part (only up to the real wallet balance) is debited through the ledger; the rest is cash on delivery. */
    protected function payments(Order $order, Currency $currency, BigDecimal $total, ?string $walletRequested): void
    {
        $wallet = BigDecimal::zero();
        if ($walletRequested !== null) {
            $balance = (string) (Wallet::query()->where('user_id', $order->user_id)->where('currency_id', $currency->id)->value('balance') ?? '0');
            $wallet = BigDecimal::of($walletRequested)->isGreaterThan($balance) ? BigDecimal::of($balance) : BigDecimal::of($walletRequested);
            $wallet = $wallet->isGreaterThan($total) ? $total : $wallet;
        }

        if ($wallet->isGreaterThan(0)) {
            $payment = OrderPayment::create([
                'order_id' => $order->id, 'payment_method_id' => PaymentMethod::query()->where('code', PaymentMethod::CODE_WALLET)->value('id'),
                'currency_id' => $currency->id, 'amount' => Money::toStorage($wallet), 'status' => OrderPayment::STATUS_PENDING,
            ]);
            $this->ledger->debitForPayment($payment);
            OrderPayment::query()->whereKey($payment->id)->update([
                'status' => OrderPayment::STATUS_PAID, 'collected_amount' => Money::toStorage($wallet), 'paid_at' => now(),
            ]);
        }

        $cash = $total->minus($wallet);
        if ($cash->isGreaterThan(0)) {
            OrderPayment::create([
                'order_id' => $order->id, 'payment_method_id' => PaymentMethod::query()->where('code', PaymentMethod::CODE_CASH)->value('id'),
                'currency_id' => $currency->id, 'amount' => Money::toStorage($cash), 'status' => OrderPayment::STATUS_PENDING,
            ]);
        }
    }

    /** Best active product offer at this moment (branch-specific or store-wide for the product). */
    protected function offerFor(int $productId, Branch $branch, BigDecimal $lineSubtotal, int $quantity, callable $convert, int $decimals): array
    {
        $offer = Offer::query()->where('product_id', $productId)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->where('starts_at', '<=', now())->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('discount_value')->first();
        if (! $offer) {
            return [null, BigDecimal::zero()];
        }

        $discount = $offer->discount_type === 'percent'
            ? Money::roundTo($lineSubtotal->multipliedBy((string) $offer->discount_value)->dividedBy(100, 8, RoundingMode::HALF_UP), $decimals)
            : $convert((string) $offer->discount_value)->multipliedBy($quantity);

        return [$offer, $discount->isGreaterThan($lineSubtotal) ? $lineSubtotal : $discount];
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
            && Order::query()->where('plus_cycle_discount_id', $row->id)->where('status', '!=', OrderStatus::Cancelled->value)->count() >= $row->max_uses_per_cycle) {
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

    protected function optionName(string $key): string
    {
        foreach (DemoCatalogSeeder::OPTION_PRESETS as [, , , , , $options]) {
            if (isset($options[$key])) {
                return $options[$key][1];
            }
        }

        throw new RuntimeException("Unknown demo option {$key}.");
    }

    /** Deterministic UUID-shaped id so re-runs never create a second copy. */
    public function uuid(string $seed): string
    {
        $h = md5('waffrli-demo:'.$seed);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
