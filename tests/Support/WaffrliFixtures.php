<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchOpeningHour;
use App\Models\BranchProduct;
use App\Models\City;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\PlusCycle;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Order\RefundService;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — realistic fixtures for service and panel tests.
 * Orders are built the way checkout will build them (snapshots, payments,
 * wallet debit through the ledger); wallets are funded only through the
 * legitimate refund path.
 */
trait WaffrliFixtures
{
    protected ?Currency $usd = null;

    protected function usd(): Currency
    {
        if ($this->usd) {
            return $this->usd;
        }

        $currency = Currency::create(['code' => 'USD', 'name' => ['en' => 'US Dollar', 'ar' => 'دولار'], 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => '1']);
        $currency->forceFill(['is_default' => true])->save();
        (new PaymentMethodSeeder)->run();

        return $this->usd = $currency->fresh();
    }

    protected function city(array $attributes = []): City
    {
        return City::create(array_merge(['name' => ['en' => 'Damascus', 'ar' => 'دمشق'], 'center_lat' => 33.5, 'center_lng' => 36.3, 'radius_km' => 25], $attributes))->fresh();
    }

    protected function store(): Store
    {
        return Store::create(['name' => ['en' => 'Store', 'ar' => 'متجر'], 'slug' => 'store-'.Str::random(6)])->fresh();
    }

    protected function branch(Store $store, City $city, bool $alwaysOpen = true): Branch
    {
        $branch = Branch::create([
            'store_id' => $store->id, 'city_id' => $city->id,
            'name' => ['en' => 'Main', 'ar' => 'الرئيسي'], 'address' => ['en' => 'Street', 'ar' => 'شارع'],
            'lat' => 33.5, 'lng' => 36.3,
        ]);

        if ($alwaysOpen) {
            foreach (range(0, 6) as $day) {
                BranchOpeningHour::create(['branch_id' => $branch->id, 'day_of_week' => $day, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
            }
        }

        return $branch->fresh();
    }

    protected function product(Store $store): Product
    {
        return Product::create(['store_id' => $store->id, 'name' => ['en' => 'Burger', 'ar' => 'برغر']])->fresh();
    }

    protected function customer(): User
    {
        return User::factory()->create(['is_active' => true, 'phone' => '+9639'.random_int(10000000, 99999999)]);
    }

    protected function driver(City $city, array $attributes = []): Driver
    {
        return Driver::create(array_merge([
            'name' => 'Driver '.Str::random(4), 'phone' => '+9639'.random_int(10000000, 99999999), 'password' => 'secret-password',
            'city_id' => $city->id, 'availability' => Driver::AVAILABILITY_AVAILABLE,
        ], $attributes))->fresh();
    }

    /**
     * @param  array{wallet?: string, cash?: string, status?: OrderStatus, type?: string, scheduled_for?: mixed, driver?: ?Driver, free_delivery?: ?PlusCycle}  $options
     */
    protected function order(User $user, Branch $branch, string $total, array $options = []): Order
    {
        $currency = $this->usd();
        $product = Product::where('store_id', $branch->store_id)->first() ?? $this->product($branch->store);
        $branchProduct = BranchProduct::firstOrCreate(['branch_id' => $branch->id, 'product_id' => $product->id], ['price' => $total]);
        $status = $options['status'] ?? OrderStatus::Pending;
        $cycle = $options['free_delivery'] ?? null;

        $order = Order::create([
            'order_number' => 'T'.strtoupper(Str::random(10)),
            'user_id' => $user->id,
            'client_request_id' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'city_id' => $branch->city_id,
            'delivery_address' => ['line' => 'Test street'],
            'delivery_lat' => 33.51, 'delivery_lng' => 36.31,
            'delivery_distance_km' => 1.2, 'distance_method' => 'haversine',
            'type' => $options['type'] ?? Order::TYPE_IMMEDIATE,
            'scheduled_for' => $options['scheduled_for'] ?? null,
            'status' => OrderStatus::Pending->value,
            'base_currency_id' => $currency->id, 'currency_id' => $currency->id, 'exchange_rate' => '1',
            'subtotal' => $total, 'delivery_fee_original' => $cycle ? '2' : '0', 'delivery_fee' => '0', 'total' => $total,
            'plus_cycle_id' => $cycle?->id, 'free_delivery_applied' => (bool) $cycle,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'branch_product_id' => $branchProduct->id,
            'product_name' => $product->getTranslations('name'), 'base_unit_price' => $total, 'unit_price' => $total,
            'quantity' => 1, 'line_subtotal' => $total, 'line_total' => $total,
        ]);

        $wallet = Money::of($options['wallet'] ?? '0');
        $cash = Money::of($options['cash'] ?? $total)->minus($wallet);
        if (isset($options['cash'])) {
            $cash = Money::of($options['cash']);
        }

        if ($wallet->isGreaterThan(0)) {
            $payment = $this->payment($order, PaymentMethod::CODE_WALLET, (string) $wallet);
            app(WalletLedger::class)->debitForPayment($payment);
            OrderPayment::whereKey($payment->id)->update(['status' => 'paid', 'collected_amount' => (string) $wallet, 'paid_at' => now()]);
        }
        if ($cash->isGreaterThan(0)) {
            $this->payment($order, PaymentMethod::CODE_CASH, (string) $cash);
        }

        $attributes = ['status' => $status->value];
        if (in_array($status, [OrderStatus::Assigned, OrderStatus::PickedUp, OrderStatus::Delivered], true)) {
            $attributes['driver_id'] = ($options['driver'] ?? $this->driver($branch->city))->id;
        }
        if ($status === OrderStatus::Delivered) {
            OrderPayment::where('order_id', $order->id)->where('status', 'pending')->get()->each(fn ($p) => OrderPayment::whereKey($p->id)->update([
                'status' => 'paid', 'collected_amount' => (string) $p->amount, 'collected_by_driver_id' => $attributes['driver_id'], 'paid_at' => now(),
            ]));
            $attributes['delivered_at'] = now();
        }
        Order::whereKey($order->id)->update($attributes);

        return $order->fresh();
    }

    protected function payment(Order $order, string $code, string $amount): OrderPayment
    {
        return OrderPayment::create([
            'order_id' => $order->id,
            'payment_method_id' => PaymentMethod::where('code', $code)->value('id'),
            'currency_id' => $order->currency_id,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    /** Funds a wallet through the only legitimate path: a refund of a delivered cash order. */
    protected function fundWallet(User $user, Branch $branch, string $amount, Admin $admin): void
    {
        $order = $this->order($user, $branch, $amount, ['status' => OrderStatus::Delivered]);
        app(RefundService::class)->refund($order->payments()->first(), $amount, 'test_funding', null, $admin, 'fund:'.Str::uuid());
    }
}
