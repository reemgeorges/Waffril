<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderPlaced;
use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderPayment;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\PlusCycle;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Services\Catalog\BranchHoursService;
use App\Services\Plus\PlusService;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-03) — checkout: turns a cart (or, for demo seeding, an
 * explicit line list) into an immutable order.
 *
 * Guarantees:
 *  - Idempotent: the client's checkout key is the order's
 *    client_request_id (UNIQUE per customer). Replaying a key returns the
 *    original order instead of creating a second one.
 *  - Serialized: the cart row, the PLUS cycle row and the wallet row are
 *    locked inside ONE transaction; wallet debit and free-delivery
 *    consumption are atomic and can never be spent twice.
 *  - Everything is re-validated and re-priced on the server
 *    (OrderPricingService); client totals are never trusted.
 *  - Every customer-visible value is snapshotted (names, options, prices,
 *    address, rate, distance, currency). Cash is collected in the order
 *    currency frozen here.
 */
class CheckoutService
{
    public function __construct(
        protected OrderPricingService $pricing,
        protected WalletLedger $ledger,
        protected PlusService $plus,
        protected BranchHoursService $hours,
    ) {}

    /**
     * @param  array{checkout_key: string, address_id?: ?int, currency_id?: ?int, wallet_amount?: string|null, use_free_delivery?: bool, scheduled_for?: ?string, notes?: ?string}  $data
     * @return array{order: Order, replayed: bool}
     */
    public function checkoutCart(User $user, int|string $cartId, array $data): array
    {
        $existing = $this->existing($user, $data['checkout_key']);
        if ($existing) {
            return ['order' => $existing, 'replayed' => true];
        }

        return DB::transaction(function () use ($user, $cartId, $data) {
            $cart = Cart::query()->whereKey($cartId)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $cart) {
                // A concurrent request with the same key may have consumed the cart.
                $existing = $this->existing($user, $data['checkout_key']);
                if ($existing) {
                    return ['order' => $existing, 'replayed' => true];
                }
                throw new BusinessRuleException(__('messages.customer.not_found'), 404);
            }

            $lines = $cart->items()->with('options')->get()->map(fn ($item) => [
                'branch_product_id' => $item->branch_product_id,
                'quantity' => $item->quantity,
                'options' => $item->options->mapWithKeys(fn ($o) => [$o->id => (int) $o->pivot->quantity])->all(),
                'notes' => $item->notes,
            ])->all();

            $order = $this->placeOrder($user, Branch::query()->findOrFail($cart->branch_id), $lines, $data);
            $cart->items()->delete();
            $cart->delete();

            return ['order' => $order, 'replayed' => false];
        });
    }

    /**
     * Core order placement (also used by the demo seeder). Must run inside a transaction.
     *
     * @param  list<array{branch_product_id: int, quantity: int, options?: array, notes?: ?string}>  $lines
     */
    public function placeOrder(User $user, Branch $branch, array $lines, array $data): Order
    {
        if (! PlatformSettings::get('orders.accepting_new')) {
            throw new BusinessRuleException(PlatformSettings::text('maintenance.message') ?? __('messages.customer.ordering_paused'), 409);
        }

        // Serialize with a default-currency switch (CurrencyRedenominationService
        // locks the currencies FOR UPDATE): an order is priced either fully
        // before or fully after the catalog conversion, never in between.
        Currency::query()->default()->sharedLock()->first();

        $address = $this->address($user, $data['address_id'] ?? null);
        if ($address->city_id !== $branch->city_id || ! $branch->is_active || ! $branch->store?->is_active) {
            throw new BusinessRuleException(__('messages.customer.not_in_your_city'), 422);
        }

        $currency = $this->currency($user, $data['currency_id'] ?? null);
        $scheduledFor = $this->validateTiming($branch, $data['scheduled_for'] ?? null);

        // Serialize everything that can be consumed: the member's active cycle.
        PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)->lockForUpdate()->get();

        $priced = $this->pricing->price($user, $branch, $lines, $currency, $address, (bool) ($data['use_free_delivery'] ?? false));
        if (! $priced['delivery_covered']) {
            throw new BusinessRuleException(__('messages.customer.delivery_not_covered'), 422);
        }
        if (! $priced['meets_min_order']) {
            throw new BusinessRuleException(__('messages.customer.below_min_order', ['amount' => $priced['min_order_amount'].' '.$currency->code]), 422);
        }

        if ($priced['free_delivery_applied'] && ! $this->plus->consumeFreeDelivery($priced['plus_cycle'])) {
            throw new BusinessRuleException(__('messages.customer.plus_request_invalid'), 409);
        }

        $total = $priced['total'];
        $walletAmount = $this->walletAmount($user, $currency, $data['wallet_amount'] ?? null, $total);
        $quote = $priced['quote'];

        $order = $this->createOrderRow($user, $branch, $address, $currency, $priced, $scheduledFor, $data);

        foreach ($priced['items'] as $item) {
            $options = $item['options'];
            unset($item['options']);
            $orderItem = OrderItem::create(array_map(fn ($v) => $v instanceof BigDecimal ? Money::toStorage($v) : $v, $item) + ['order_id' => $order->id]);
            foreach ($options as $option) {
                OrderItemOption::create($option + ['order_item_id' => $orderItem->id]);
            }
        }

        OrderStatusHistory::create([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending->value,
            'actor_type' => 'customer', 'actor_id' => $user->id, 'note' => $data['history_note'] ?? null,
        ]);

        if ($walletAmount->isGreaterThan(0)) {
            $payment = OrderPayment::create([
                'order_id' => $order->id, 'payment_method_id' => $this->method(PaymentMethod::CODE_WALLET),
                'currency_id' => $currency->id, 'amount' => Money::toStorage($walletAmount), 'status' => OrderPayment::STATUS_PENDING,
            ]);
            $this->ledger->debitForPayment($payment);
            OrderPayment::query()->whereKey($payment->id)->update(['status' => OrderPayment::STATUS_PAID, 'collected_amount' => Money::toStorage($walletAmount), 'paid_at' => now()]);
        }
        $cash = $total->minus($walletAmount);
        if ($cash->isGreaterThan(0)) {
            OrderPayment::create([
                'order_id' => $order->id, 'payment_method_id' => $this->method(PaymentMethod::CODE_CASH),
                'currency_id' => $currency->id, 'amount' => Money::toStorage($cash), 'status' => OrderPayment::STATUS_PENDING,
            ]);
        }

        DB::afterCommit(fn () => event(new OrderPlaced($order->id)));

        return $order->fresh(['items.options', 'payments.method', 'currency', 'branch.store']);
    }

    protected function createOrderRow(User $user, Branch $branch, UserAddress $address, Currency $currency, array $priced, ?Carbon $scheduledFor, array $data): Order
    {
        $attributes = [
            'user_id' => $user->id,
            'client_request_id' => $data['checkout_key'],
            'branch_id' => $branch->id,
            'city_id' => $branch->city_id,
            'delivery_area_id' => $priced['quote']->deliveryAreaId,
            'delivery_fee_rule_id' => $priced['quote']->deliveryFeeRuleId,
            'user_address_id' => $address->id,
            'delivery_address' => ['label' => $address->label, 'line' => $address->address_line, 'details' => $address->details],
            'delivery_lat' => $address->lat, 'delivery_lng' => $address->lng,
            'delivery_distance_km' => round($priced['quote']->distanceKm, 3), 'distance_method' => $priced['quote']->distanceMethod,
            'type' => $scheduledFor ? Order::TYPE_SCHEDULED : Order::TYPE_IMMEDIATE,
            'scheduled_for' => $scheduledFor,
            'status' => OrderStatus::Pending->value,
            'base_currency_id' => $priced['default_currency']->id, 'currency_id' => $currency->id, 'exchange_rate' => $priced['exchange_rate'],
            'subtotal' => Money::toStorage($priced['subtotal']),
            'offer_discount_total' => Money::toStorage($priced['offer_discount_total']),
            'plus_discount_amount' => Money::toStorage($priced['plus_discount_amount']),
            'delivery_fee_original' => Money::toStorage($priced['delivery_fee_original']),
            'delivery_fee' => Money::toStorage($priced['delivery_fee']),
            'total' => Money::toStorage($priced['total']),
            'plus_cycle_id' => ($priced['free_delivery_applied'] || $priced['plus_cycle_discount']) ? $priced['plus_cycle']->id : null,
            'plus_cycle_discount_id' => $priced['plus_cycle_discount']?->id,
            'free_delivery_applied' => $priced['free_delivery_applied'],
            'customer_notes' => $data['notes'] ?? null,
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::create($attributes + ['order_number' => $data['order_number'] ?? self::orderNumber()]);
            } catch (QueryException $e) {
                if (isset($data['order_number']) || ! str_contains(strtolower($e->getMessage()), 'order_number')) {
                    throw $e;
                }
            }
        }
        throw new BusinessRuleException(__('messages.customer.checkout_conflict'), 409);
    }

    public static function orderNumber(): string
    {
        return 'WF'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    protected function existing(User $user, string $key): ?Order
    {
        return Order::query()->where('user_id', $user->id)->where('client_request_id', $key)->first()?->load(['items.options', 'payments.method', 'currency', 'branch.store']);
    }

    protected function address(User $user, ?int $addressId): UserAddress
    {
        $address = $addressId
            ? $user->addresses()->whereKey($addressId)->first()
            : $user->addresses()->where('is_default', true)->first();
        if (! $address) {
            throw new BusinessRuleException(__('messages.customer.no_delivery_address'), 422);
        }

        return $address;
    }

    /** Payment currency: requested, else the display currency, else the default. Frozen on the order. */
    protected function currency(User $user, ?int $currencyId): Currency
    {
        $id = $currencyId ?? $user->display_currency_id;
        $currency = $id ? Currency::query()->whereKey($id)->where('is_active', true)->first() : Currency::query()->default()->first();
        if (! $currency) {
            throw new BusinessRuleException(__('messages.customer.currency_not_payable'), 422);
        }

        return $currency;
    }

    protected function validateTiming(Branch $branch, ?string $scheduledFor): ?Carbon
    {
        if ($scheduledFor === null) {
            if (! $branch->is_accepting_orders) {
                throw new BusinessRuleException(__('messages.customer.branch_not_accepting'), 422);
            }
            if (! $this->hours->isOpenAt($branch, now())) {
                throw new BusinessRuleException(__('messages.customer.branch_closed'), 422);
            }

            return null;
        }

        $at = Carbon::parse($scheduledFor)->utc();
        $from = now()->addMinutes((int) PlatformSettings::get('orders.scheduled_min_lead_minutes'));
        $to = now()->addDays((int) PlatformSettings::get('orders.scheduled_max_days_ahead'));
        if ($at->lt($from) || $at->gt($to)) {
            throw new BusinessRuleException(__('messages.customer.schedule_invalid', ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]), 422);
        }
        if (! $this->hours->isOpenAt($branch, $at)) {
            throw new BusinessRuleException(__('messages.customer.branch_closed'), 422);
        }

        return $at;
    }

    /** Wallet part in the ORDER currency only (wallets are never converted). */
    protected function walletAmount(User $user, Currency $currency, ?string $requested, BigDecimal $total): BigDecimal
    {
        if ($requested === null || $requested === '' || ($requested !== 'max' && BigDecimal::of($requested)->isZero())) {
            return BigDecimal::zero();
        }
        $amount = $requested === 'max'
            ? BigDecimal::of((string) (Wallet::query()->where('user_id', $user->id)->where('currency_id', $currency->id)->value('balance') ?? '0'))
            : BigDecimal::of($requested);
        $amount = $amount->isGreaterThan($total) ? $total : $amount;
        if ($amount->isZero()) {
            return BigDecimal::zero();
        }
        $balance = BigDecimal::of((string) (Wallet::query()->where('user_id', $user->id)->where('currency_id', $currency->id)->lockForUpdate()->value('balance') ?? '0'));
        if ($amount->isGreaterThan($balance)) {
            throw new BusinessRuleException(__('messages.customer.wallet_insufficient', ['currency' => $currency->code, 'balance' => (string) Money::roundTo($balance, $currency->decimals)]), 422);
        }

        return Money::roundTo($amount, 4);
    }

    protected function method(string $code): int
    {
        return (int) PaymentMethod::query()->where('code', $code)->where('is_active', true)->valueOrFail('id');
    }
}
