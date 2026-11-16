<?php

namespace Tests\Feature\Customer;

use App\Models\City;
use App\Models\Currency;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Store;
use Database\Seeders\Demo\DemoHamaSeeder;
use RuntimeException;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-16) — the regional demo seeders on a database that
 * already holds real data (e.g. a developer's local database with test
 * orders): reference data is added, demo transactions are not.
 */
class RegionalDemoSeederSafetyTest extends CustomerTestCase
{
    use WaffrliFixtures;

    protected bool $seedDemo = false;

    protected function realLocalData(): Order
    {
        $branch = $this->branch($this->store(), $this->city());
        $order = $this->order($this->customer(), $branch, '10'); // a non-demo order number
        Currency::query()->firstOrCreate(['code' => 'SYP'], ['name' => ['ar' => 'ليرة سورية', 'en' => 'Syrian Pound'], 'symbol' => 'SYP', 'decimals' => 0, 'exchange_rate' => '13000', 'is_active' => true]);

        return $order;
    }

    public function test_reference_data_is_added_next_to_real_orders_but_no_demo_transactions(): void
    {
        $real = $this->realLocalData();
        $ordersBefore = Order::count();

        $this->seed(DemoHamaSeeder::class);

        $hama = City::query()->where('name->en', 'Hama')->firstOrFail();
        $this->assertGreaterThan(30, DeliveryArea::query()->where('city_id', $hama->id)->count(), 'Delivery areas are added.');
        $this->assertSame(8, Store::query()->where('slug', 'like', 'demo-hama-%')->count(), 'Stores are added.');
        $this->assertSame($ordersBefore, Order::count(), 'No demo order next to real orders.');
        $this->assertSame(0, Order::query()->where('order_number', 'like', 'DEMO-HAMA-%')->count());
        $this->assertNotNull($real->fresh(), 'Real data is untouched.');
    }

    public function test_never_in_production(): void
    {
        $this->realLocalData();
        $this->app->detectEnvironment(fn () => 'production');

        // Called directly: db:seed's own production prompt would come first.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may only be seeded in');
        (new DemoHamaSeeder)->setContainer($this->app)->run();
    }
}
