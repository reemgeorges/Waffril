<?php

namespace Tests\Feature\Currency;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\CurrencyRedenomination;
use App\Services\Currency\CurrencyRedenominationService;
use Database\Seeders\WaffrliDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-03) — MySQL 8 concurrency and integrity of the
 * default-currency switch, with a SECOND real connection holding locks the
 * way a concurrent checkout or a second worker would. Rows are committed
 * (DatabaseTruncation, not a wrapping transaction) so both connections see
 * them; only ever run against the disposable testing database.
 *
 * Skipped on SQLite (no row locks / concurrent connections).
 */
class CurrencyRedenominationMySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        // Decide before the app (and DatabaseTruncation) boots: never touch the SQLite run.
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency test.');
        }
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(WaffrliDemoSeeder::class);
        config([
            'waffrli.currency.redenomination_enabled' => true,
            'database.connections.mysql_concurrent' => config('database.connections.mysql'),
        ]);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
    }

    protected function tearDown(): void
    {
        if (! $this->app) {
            return;
        }
        if (DB::getConnections()['mysql_concurrent'] ?? null) {
            DB::connection('mysql_concurrent')->rollBack(0);
            DB::purge('mysql_concurrent');
        }
        // DatabaseTruncation empties tables BEFORE a test; leave nothing committed for later tests.
        $this->purgeCommittedRowsOnMySql();
        parent::tearDown();
    }

    protected function admin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    protected function request(string $key): CurrencyRedenomination
    {
        return app(CurrencyRedenominationService::class)->request($this->admin(), Currency::where('code', 'SYP')->value('id'), $key);
    }

    public function test_a_checkout_holding_the_default_currency_blocks_the_switch_which_stays_pending_and_resumes(): void
    {
        $prices = DB::table('branch_products')->orderBy('id')->pluck('price')->all();
        $run = $this->request('mysql-checkout-lock');

        // Another connection is inside a checkout: shared lock on the default currency.
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->table('currencies')->where('is_default', true)->sharedLock()->first();

        try {
            app(CurrencyRedenominationService::class)->execute($run);
            $this->fail('The switch must wait for the checkout.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], 'Lock wait timeout.');
        }
        $this->assertSame(CurrencyRedenomination::STATUS_PENDING, $run->fresh()->status, 'Transient: still pending, not failed.');
        $this->assertSame($prices, DB::table('branch_products')->orderBy('id')->pluck('price')->all(), 'Nothing converted.');
        $this->assertSame('USD', Currency::where('is_default', true)->value('code'));

        // The checkout commits; the resumed run completes exactly once.
        $other->commit();
        $this->assertSame(CurrencyRedenomination::STATUS_COMPLETED, app(CurrencyRedenominationService::class)->execute($run)->status);
        $this->assertSame('SYP', Currency::where('is_default', true)->value('code'));
        $converted = DB::table('branch_products')->orderBy('id')->pluck('price')->all();
        $this->assertNotSame($prices, $converted);
        app(CurrencyRedenominationService::class)->execute($run->fresh());
        $this->assertSame($converted, DB::table('branch_products')->orderBy('id')->pluck('price')->all(), 'Never converted twice.');
    }

    public function test_a_running_switch_blocks_checkout_pricing_until_it_commits(): void
    {
        // The switch's first step: exclusive lock on the currencies (held by the other connection here).
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->table('currencies')->orderBy('id')->lockForUpdate()->get();

        try {
            DB::transaction(fn () => Currency::query()->default()->sharedLock()->first()); // what CheckoutService::placeOrder does first
            $this->fail('Checkout must wait for the switch.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        }
        $other->rollBack();
        $this->assertNotNull(DB::transaction(fn () => Currency::query()->default()->sharedLock()->first()));
    }

    public function test_two_workers_executing_the_same_run_convert_once(): void
    {
        $run = $this->request('mysql-two-workers');

        // Worker A holds the run row (as execute() does first).
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->table('currency_redenominations')->where('id', $run->id)->lockForUpdate()->first();

        try {
            app(CurrencyRedenominationService::class)->execute($run); // worker B
            $this->fail('Worker B must wait for worker A.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        }
        $other->rollBack();

        $before = DB::table('branch_products')->orderBy('id')->pluck('price')->all();
        app(CurrencyRedenominationService::class)->execute($run);
        $once = DB::table('branch_products')->orderBy('id')->pluck('price')->all();
        app(CurrencyRedenominationService::class)->execute($run);
        $this->assertNotSame($before, $once);
        $this->assertSame($once, DB::table('branch_products')->orderBy('id')->pluck('price')->all());
    }

    public function test_database_guarantees_single_in_flight_run_and_single_default(): void
    {
        $this->request('mysql-first');
        try {
            $this->request('mysql-second');
            $this->fail('The unique in-flight marker allows one pending/running run.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }

        // Even raw SQL cannot create two defaults or a default with rate <> 1.
        $this->expectException(QueryException::class);
        DB::table('currencies')->where('code', 'SYP')->update(['is_default' => true]);
    }
}
