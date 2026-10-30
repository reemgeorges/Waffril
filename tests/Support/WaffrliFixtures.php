<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchOpeningHour;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\City;
use App\Models\Currency;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\PlusStoreDiscount;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Catalog\ProductRatingService;
use App\Services\Driver\DriverCashSettlementService;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
use App\Services\Plus\PlusService;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — realistic fixtures for service and panel tests.
 * Orders are built the way checkout will build them (snapshots, payments,
 * wallet debit through the ledger); wallets are funded only through the
 * legitimate refund path. seedFullDataset() puts one valid row in every
 * constrained table (MySQL constraint and panel integrity tests).
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

    /** One valid row in every table that carries a CHECK constraint, built through the real services. */
    protected function seedFullDataset(): array
    {
        $admin = $this->superAdmin();
        $usd = $this->usd();
        $syp = Currency::create(['code' => 'SYP', 'name' => ['en' => 'Pound'], 'symbol' => 'S', 'decimals' => 0, 'exchange_rate' => '13000']);
        $city = $this->city();
        $store = $this->store();
        $branch = $this->branch($store, $city);
        $product = $this->product($store);
        $user = $this->customer();
        $driver = $this->driver($city);

        $group = ProductOptionGroup::create(['product_id' => $product->id, 'name' => ['en' => 'Extras'], 'kind' => 'choice', 'min_select' => 0, 'max_select' => 1]);
        $option = ProductOption::create(['option_group_id' => $group->id, 'product_id' => $product->id, 'name' => ['en' => 'Cheese']]);

        // Wallet, wallet transaction, delivered order, items, payment and refund.
        $this->fundWallet($user, $branch, '10', $admin);
        $branchProduct = BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
        DB::table('branch_product_options')->insert(['branch_product_id' => $branchProduct->id, 'product_id' => $product->id, 'product_option_id' => $option->id, 'price_delta' => 1]);
        DB::table('order_item_options')->insert([
            'order_item_id' => DB::table('order_items')->min('id'), 'product_option_id' => $option->id,
            'group_name' => '{"en":"Extras"}', 'option_name' => '{"en":"Cheese"}', 'unit_price_delta' => 1, 'quantity' => 2, 'total_price_delta' => 2,
        ]);

        // Admin cancellation of a pending order (customer-pending rules) and a pending request on another order.
        $pending = $this->order($user, $branch, '5');
        app(OrderAdminService::class)->cancelByAdmin($pending, $admin, ['reason_code' => 'other']);
        DB::table('order_cancellation_requests')->insert(['order_id' => $this->order($user, $branch, '6')->id, 'status' => 'pending']);

        // Delivered cash order for a settlement; rating on the delivered order.
        $this->order($user, $branch, '7', ['status' => OrderStatus::Delivered, 'driver' => $driver]);
        app(DriverCashSettlementService::class)->prepare($driver, $usd, now()->toDateString(), $admin);
        app(ProductRatingService::class)->record($user, $product, Order::where('status', 'delivered')->firstOrFail(), 4, 'good');

        // PLUS: plan discount, new request approved into membership/cycle/snapshot.
        $plan = PlusPlan::create(['code' => 'gold', 'name' => ['en' => 'Gold'], 'free_deliveries_per_cycle' => 2, 'display_price' => 5]);
        PlusStoreDiscount::create(['plus_plan_id' => $plan->id, 'store_id' => $store->id, 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount_amount' => 5]);
        app(PlusService::class)->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id]), $admin, PlusMembership::POLICY_MONTHLY);

        // Catalog taxonomies and a stored (never sent) admin notification.
        $storeCategory = StoreCategory::create(['name' => ['en' => 'Restaurants', 'ar' => 'مطاعم'], 'slug' => 'restaurants']);
        $store->categories()->attach($storeCategory->id);
        $category = Category::create(['name' => ['en' => 'Burgers', 'ar' => 'برغر'], 'slug' => 'burgers']);
        $product->categories()->attach($category->id);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\OrderPlaced',
            'notifiable_type' => Admin::class, 'notifiable_id' => $admin->id,
            'data' => json_encode(['message' => 'New order']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Remaining tables, inserted directly with valid values.
        $cartId = DB::table('carts')->insertGetId(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $cartItemId = DB::table('cart_items')->insertGetId(['cart_id' => $cartId, 'branch_product_id' => $branchProduct->id, 'configuration_hash' => 'h1', 'quantity' => 1]);
        DB::table('cart_item_options')->insert(['cart_item_id' => $cartItemId, 'product_option_id' => $option->id, 'quantity' => 1]);
        DB::table('branch_hour_exceptions')->insert(['branch_id' => $branch->id, 'kind' => 'closed', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'created_by_admin_id' => $admin->id]);
        DB::table('offers')->insert(['store_id' => $store->id, 'product_id' => $product->id, 'discount_type' => 'percent', 'discount_value' => 10, 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_by_admin_id' => $admin->id]);
        UserAddress::create(['user_id' => $user->id, 'city_id' => $city->id, 'address_line' => 'Street 1', 'lat' => 33.5, 'lng' => 36.3, 'is_default' => true]);
        $areaId = DB::table('delivery_areas')->insertGetId(['city_id' => $city->id, 'name' => '{"en":"Area"}', 'boundary' => '{}', 'min_lat' => 33, 'max_lat' => 34, 'min_lng' => 36, 'max_lng' => 37]);
        DB::table('delivery_fee_rules')->insert(['city_id' => $city->id, 'delivery_area_id' => $areaId, 'distance_from_km' => 0, 'distance_to_km' => 5, 'base_fee' => 1, 'per_km_fee' => 0.5]);
        DB::table('currency_redenominations')->insert(['from_currency_id' => $usd->id, 'to_currency_id' => $syp->id, 'conversion_rate' => 13000, 'idempotency_key' => 'r1', 'requested_by_admin_id' => $admin->id]);
        DB::table('settings')->insert(['key' => 'general.platform_name', 'value' => '"Waffrli"']);
        DB::table('otps')->insert(['verifiable_type' => User::class, 'verifiable_id' => $user->id, 'purpose' => 'login', 'channel' => 'sms', 'code_hash' => 'x', 'max_attempts' => 5, 'expires_at' => now()->addMinutes(5)]);
        DB::table('marketing_broadcasts')->insert(['admin_id' => $admin->id, 'title' => '{"ar":"مرحبا","en":"Hi"}', 'body' => '{"ar":"أخبار","en":"News"}', 'status' => 'sent', 'recipients_count' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return compact('admin', 'usd', 'syp', 'city', 'store', 'branch', 'product', 'user', 'group', 'option', 'plan', 'areaId');
    }
}
