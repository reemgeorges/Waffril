<?php

namespace Database\Seeders\LoadTest;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\City;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Order\CheckoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * WAFFRLI (2026-11-21) — DISPOSABLE load-test dataset for an ISOLATED staging
 * or local database (docs/performance/benchmark-plan.md). Never production.
 *
 * Adds on top of WaffrliDemoSeeder (which provides cities, stores, branches,
 * products and delivery areas):
 *  - N customers (default 2,000) with a default address in the demo city,
 *    identified by the reserved domain @loadtest.invalid;
 *  - M drivers (default 40) in the same city, available;
 *  - order history through the REAL CheckoutService::placeOrder (same
 *    validation, pricing and payments), back-dated, then marked delivered;
 *  - one Sanctum token per customer and per driver, written ONLY to
 *    storage/app/loadtest/tokens.json (git-ignored), never printed.
 *
 * Guards: APP_ENV must be local, testing or staging AND
 * WAFFRLI_LOADTEST_SEED=yes must be set explicitly. Notifications are muted
 * while seeding (nothing is sent: no SMS, no push). Re-running adds only what
 * is missing (natural keys) and re-issues tokens.
 *
 *   WAFFRLI_LOADTEST_SEED=yes php artisan db:seed --class="Database\Seeders\LoadTest\LoadTestDatasetSeeder"
 *
 * Sizes: LOADTEST_CUSTOMERS, LOADTEST_DRIVERS, LOADTEST_ORDERS_PER_CUSTOMER,
 * LOADTEST_CITY (English name, default Hama).
 */
class LoadTestDatasetSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'loadtest.invalid';

    public const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public static function assertAllowed(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException('Load-test data may only be seeded in: '.implode(', ', self::ALLOWED_ENVIRONMENTS).'. Current: '.app()->environment().'.');
        }
        if (env('WAFFRLI_LOADTEST_SEED') !== 'yes') {
            throw new RuntimeException('Set WAFFRLI_LOADTEST_SEED=yes to confirm this is a disposable load-test database.');
        }
    }

    public function run(): void
    {
        self::assertAllowed();

        $customers = (int) env('LOADTEST_CUSTOMERS', 2000);
        $drivers = (int) env('LOADTEST_DRIVERS', 40);
        $ordersEach = (int) env('LOADTEST_ORDERS_PER_CUSTOMER', 3);
        $city = City::query()->where('name->en', env('LOADTEST_CITY', 'Hama'))->first();
        if (! $city) {
            throw new RuntimeException('Run WaffrliDemoSeeder first (it creates the demo city, stores and areas).');
        }

        $branches = Branch::query()->where('city_id', $city->id)->where('is_active', true)
            ->whereHas('store', fn ($q) => $q->where('is_active', true))->get();
        if ($branches->isEmpty()) {
            throw new RuntimeException("No active branch in {$city->getTranslation('name', 'en')}.");
        }

        config(['waffrli.notifications_muted' => true]);   // nothing is sent while seeding
        $tokens = ['generated_at' => now()->toIso8601String(), 'base_city_id' => $city->id, 'customers' => [], 'drivers' => [], 'branches' => []];

        // Customers: a default address near a random branch of the city (inside the service area).
        $existing = User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id', 'email');
        $this->command?->info("Customers: {$existing->count()} existing, target {$customers}.");
        for ($n = 1; $n <= $customers; $n++) {
            $email = sprintf('lt-%05d@%s', $n, self::EMAIL_DOMAIN);
            if (! isset($existing[$email])) {
                DB::transaction(function () use ($n, $email, $city, $branches) {
                    $user = User::create([
                        'name' => "Load Test {$n}", 'first_name' => 'Load', 'last_name' => "Test {$n}",
                        'email' => $email, 'email_verified_at' => now(), 'password' => Str::random(40),
                        // +999 is an unassigned E.164 country code: never a real phone.
                        'phone' => sprintf('+99955%07d', $n), 'language' => $n % 3 === 0 ? 'en' : 'ar',
                        'is_active' => true, 'otp_channel' => 'sms',
                    ]);
                    $user->forceFill(['phone_verified_at' => now(), 'profile_completed_at' => now()])->save();
                    $branch = $branches[$n % $branches->count()];
                    UserAddress::create([
                        'user_id' => $user->id, 'city_id' => $city->id, 'label' => 'Home', 'address_line' => "Load test street {$n}",
                        'details' => 'Load-test address', 'is_default' => true,
                        'lat' => round((float) $branch->lat + (mt_rand(-30, 30) / 10000), 7),
                        'lng' => round((float) $branch->lng + (mt_rand(-30, 30) / 10000), 7),
                    ]);
                });
            }
        }

        for ($n = 1; $n <= $drivers; $n++) {
            Driver::query()->firstOrCreate(['phone' => sprintf('+99966%07d', $n)], [
                'name' => "Load Driver {$n}", 'password' => Str::random(40), 'city_id' => $city->id,
                'vehicle_type' => 'motorcycle', 'vehicle_plate' => sprintf('LT-%04d', $n),
                'is_active' => true, 'availability' => Driver::AVAILABILITY_AVAILABLE, 'language' => 'ar',
            ]);
        }

        $this->orderHistory($city, $branches, $ordersEach);
        $this->activeDeliveries();

        // Tokens (re-issued on every run; old load-test tokens are removed).
        $userIds = User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->orderBy('id')->pluck('id');
        $driverModels = Driver::query()->where('phone', 'like', '+99966%')->orderBy('id')->get();
        DB::table('personal_access_tokens')->where('name', 'loadtest')->delete();
        foreach (User::query()->whereIn('id', $userIds)->with(['addresses' => fn ($q) => $q->where('is_default', true)])->lazyById(500) as $user) {
            $tokens['customers'][] = ['id' => $user->id, 'token' => $user->createToken('loadtest', ['user', '*'])->plainTextToken,
                'address_id' => $user->addresses->first()?->id];
        }
        foreach ($driverModels as $driver) {
            $tokens['drivers'][] = ['id' => $driver->id, 'token' => $driver->createToken('loadtest', ['driver', '*'])->plainTextToken];
        }
        foreach ($this->menus($branches) as $branchId => $menu) {
            $tokens['branches'][] = ['id' => $branchId, 'product_ids' => $menu['product_ids'], 'cheapest_product_id' => $menu['cheapest_product_id'], 'min_quantity' => $menu['min_quantity']];
        }

        $path = storage_path('app/loadtest/tokens.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($tokens));
        @chmod($path, 0600);
        $this->command?->info(sprintf('Tokens: %d customers, %d drivers, %d branches → storage/app/loadtest/tokens.json (keep private).',
            count($tokens['customers']), count($tokens['drivers']), count($tokens['branches'])));
    }

    /**
     * One in-progress delivery per load-test driver (a history order moved to
     * picked_up), so GPS pings take the full path: position update + live
     * broadcast to the customer. LOADTEST_ACTIVE_DELIVERIES=no skips it.
     */
    protected function activeDeliveries(): void
    {
        if (env('LOADTEST_ACTIVE_DELIVERIES', 'yes') !== 'yes') {
            return;
        }
        $drivers = Driver::query()->where('phone', 'like', '+99966%')->orderBy('id')->get();
        $busy = Order::query()->whereIn('driver_id', $drivers->pluck('id'))->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::PickedUp->value])->pluck('driver_id')->all();
        $orders = Order::query()->whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::EMAIL_DOMAIN))
            ->where('status', OrderStatus::Delivered->value)->whereNull('driver_id')->orderByDesc('id')->limit($drivers->count())->get();
        $n = 0;
        foreach ($drivers as $i => $driver) {
            if (in_array($driver->id, $busy, true) || ! isset($orders[$i])) {
                continue;
            }
            Order::query()->whereKey($orders[$i]->id)->update(['status' => OrderStatus::PickedUp->value, 'driver_id' => $driver->id,
                'delivered_at' => null, 'picked_up_at' => now()]);
            $n++;
        }
        $this->command?->info("Active deliveries: {$n} drivers given an in-progress order.");
    }

    /**
     * Per branch: orderable products (no required options), the cheapest one
     * and the quantity of it that reaches the branch minimum order (stored
     * amounts are both in the accounting base, so they compare directly).
     *
     * @return Collection<int, array{ids: list<int>, product_ids: list<int>, cheapest_id: int, cheapest_product_id: int, min_quantity: int}>
     */
    protected function menus($branches)
    {
        return $branches->mapWithKeys(function (Branch $b) {
            $rows = BranchProduct::query()->where('branch_id', $b->id)->where('is_available', true)
                ->whereDoesntHave('product.optionGroups', fn ($q) => $q->where('is_active', true)->where('min_select', '>', 0))
                ->orderBy('price')->get(['id', 'product_id', 'price']);
            if ($rows->isEmpty()) {
                return [];
            }
            $cheapest = $rows->first();
            $min = (float) ($b->min_order_amount ?? 0);
            $quantity = max(1, (int) ceil($min / max(0.0001, (float) $cheapest->price)) + 1);

            return [$b->id => ['ids' => $rows->pluck('id')->all(), 'product_ids' => $rows->pluck('product_id')->all(),
                'cheapest_id' => $cheapest->id, 'cheapest_product_id' => $cheapest->product_id, 'min_quantity' => min($quantity, 20)]];
        });
    }

    /** Back-dated, delivered history through the real checkout (indexes see realistic volumes). */
    protected function orderHistory(City $city, $branches, int $ordersEach): void
    {
        if ($ordersEach <= 0) {
            return;
        }
        $have = Order::query()->whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::EMAIL_DOMAIN))->count();
        $users = User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->orderBy('id')->get();
        $target = $users->count() * $ordersEach;
        if ($have >= $target) {
            $this->command?->info("Orders: {$have} already present.");

            return;
        }

        $menus = $this->menus($branches);
        $checkout = app(CheckoutService::class);
        $placed = 0;
        $failed = 0;
        $start = Carbon::now();
        foreach ($users as $i => $user) {
            $address = $user->addresses()->where('is_default', true)->first();
            for ($k = 0; $k < $ordersEach; $k++) {
                if ($have + $placed >= $target) {
                    break 2;
                }
                $branchId = $menus->keys()[($i + $k) % $menus->count()];
                $menu = $menus[$branchId];
                // Midday Damascus time, 1–60 days ago: branches are open.
                $moment = $start->copy()->subDays(1 + (($i * 7 + $k * 13) % 60))->setTime(10, 0)->addMinutes(($i + $k) % 240);
                Carbon::setTestNow($moment);
                try {
                    DB::transaction(function () use ($checkout, $user, $branchId, $menu, $address, $i, $k, $moment) {
                        // The cheapest item, enough of it to reach the branch minimum.
                        $lines = [['branch_product_id' => $menu['cheapest_id'], 'quantity' => $menu['min_quantity'] + (($i + $k) % 2), 'options' => [], 'notes' => null]];
                        $order = $checkout->placeOrder($user, Branch::query()->findOrFail($branchId), $lines, [
                            'checkout_key' => (string) Str::uuid(), 'address_id' => $address?->id, 'use_free_delivery' => false,
                            'history_note' => 'Load-test history',
                        ]);
                        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Delivered->value, 'delivered_at' => $moment->copy()->addMinutes(40)]);
                        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => OrderStatus::Pending->value,
                            'to_status' => OrderStatus::Delivered->value, 'actor_type' => 'system', 'actor_id' => null, 'note' => 'Load-test history']);
                    });
                    $placed++;
                } catch (Throwable $e) {
                    $failed++;
                    if ($failed <= 3) {
                        $this->command?->warn('Order skipped: '.$e->getMessage());
                    }
                } finally {
                    Carbon::setTestNow();
                }
            }
        }
        $this->command?->info("Orders: {$placed} placed, {$failed} skipped.");
    }
}
