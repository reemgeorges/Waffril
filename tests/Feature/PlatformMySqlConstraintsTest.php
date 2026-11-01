<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — database-level constraints that only exist
 * on MySQL 8 (CHECK constraints). Skipped unless the test connection is
 * an ISOLATED MySQL 8 test database, e.g.:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=waffrli_test ... vendor/bin/phpunit --group mysql
 *
 * A skip here must be reported as NOT RUN, never as passed.
 */
#[Group('mysql')]
class PlatformMySqlConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT RUN: requires an isolated MySQL 8 test database (current driver: '.DB::connection()->getDriverName().').');
        }
    }

    protected function assertRejected(callable $statement): void
    {
        try {
            $statement();
            $this->fail('Expected the database to reject the statement.');
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [3819, 1062, 1451, 1452]);
        }
    }

    public function test_currency_checks(): void
    {
        $insert = fn (array $row) => DB::table('currencies')->insert(array_merge([
            'code' => 'USD', 'name' => '{"en":"US Dollar"}', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1,
        ], $row));

        $this->assertRejected(fn () => $insert(['exchange_rate' => 0]));
        $this->assertRejected(fn () => $insert(['decimals' => 5]));
        $this->assertRejected(fn () => $insert(['code' => 'us1']));
        $this->assertRejected(fn () => $insert(['is_default' => 1, 'exchange_rate' => 2]));
        $this->assertRejected(fn () => $insert(['is_default' => 1, 'is_active' => 0]));

        $insert(['is_default' => 1]);
        $this->assertRejected(fn () => $insert(['code' => 'EUR', 'is_default' => 1]));
        $this->assertSame(1, Currency::count());
    }

    public function test_redenomination_single_inflight_and_status_checks(): void
    {
        $adminId = DB::table('admins')->insertGetId(['name' => 'a', 'email' => 'a@example.test', 'password' => 'x']);
        $usd = DB::table('currencies')->insertGetId(['code' => 'USD', 'name' => '{}', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => 1]);
        $syp = DB::table('currencies')->insertGetId(['code' => 'SYP', 'name' => '{}', 'symbol' => 'S', 'decimals' => 2, 'exchange_rate' => 13000]);

        $row = fn (array $o) => array_merge([
            'from_currency_id' => $usd, 'to_currency_id' => $syp, 'conversion_rate' => 13000,
            'requested_by_admin_id' => $adminId, 'idempotency_key' => bin2hex(random_bytes(8)),
        ], $o);

        DB::table('currency_redenominations')->insert($row(['status' => 'pending']));
        $this->assertRejected(fn () => DB::table('currency_redenominations')->insert($row(['status' => 'running'])));
        $this->assertRejected(fn () => DB::table('currency_redenominations')->insert($row(['status' => 'bogus'])));
        $this->assertRejected(fn () => DB::table('currency_redenominations')->insert($row(['status' => 'completed', 'to_currency_id' => $usd])));
        $this->assertRejected(fn () => DB::table('currencies')->where('id', $usd)->delete());
    }

    public function test_city_area_and_fee_rule_checks(): void
    {
        $cityId = City::create(['name' => ['en' => 'C', 'ar' => 'C'], 'center_lat' => 1, 'center_lng' => 1, 'radius_km' => 5])->id;

        $this->assertRejected(fn () => DB::table('cities')->insert(['name' => '{}', 'center_lat' => 91, 'center_lng' => 0, 'radius_km' => 1]));
        $this->assertRejected(fn () => DB::table('cities')->insert(['name' => '{}', 'center_lat' => 0, 'center_lng' => 0, 'radius_km' => 0]));
        $this->assertRejected(fn () => DB::table('delivery_areas')->insert([
            'city_id' => $cityId, 'name' => '{}', 'boundary' => '{}', 'min_lat' => 2, 'max_lat' => 1, 'min_lng' => 0, 'max_lng' => 1,
        ]));

        $areaId = DB::table('delivery_areas')->insertGetId([
            'city_id' => $cityId, 'name' => '{}', 'boundary' => '{}', 'min_lat' => 0, 'max_lat' => 1, 'min_lng' => 0, 'max_lng' => 1,
        ]);

        $this->assertRejected(fn () => DB::table('delivery_fee_rules')->insert(['delivery_area_id' => $areaId, 'distance_to_km' => 5]));
        $this->assertRejected(fn () => DB::table('delivery_fee_rules')->insert(['distance_from_km' => 5, 'distance_to_km' => 5]));
        $this->assertRejected(fn () => DB::table('delivery_fee_rules')->insert(['distance_to_km' => 5, 'base_fee' => -1]));

        DB::table('delivery_fee_rules')->insert(['city_id' => $cityId, 'distance_from_km' => 0, 'distance_to_km' => 5]);
        $this->assertSame('c:'.$cityId, DB::table('delivery_fee_rules')->value('scope_key'));
        $this->assertRejected(fn () => DB::table('delivery_fee_rules')->insert(['city_id' => $cityId, 'distance_from_km' => 0, 'distance_to_km' => 9]));
        $this->assertRejected(fn () => DB::table('cities')->where('id', $cityId)->delete());
    }

    public function test_settings_key_check(): void
    {
        $this->assertRejected(fn () => DB::table('settings')->insert(['key' => 'Bad Key', 'value' => '1']));
    }
}
