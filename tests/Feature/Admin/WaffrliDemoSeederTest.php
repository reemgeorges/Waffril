<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\Waffrli\DatabaseDocsCommand;
use App\Enums\OrderStatus;
use App\Filament\Widgets\FinanceOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\PlatformOverview;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PlusCycle;
use App\Models\PlusCycleDiscount;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedger;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Essential\AdminRoleTemplatesSeeder;
use Database\Seeders\WaffrliDemoSeeder;
use Database\Seeders\WaffrliEssentialSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * WAFFRLI (2026-11-01) — essential + demo seeders: safety, idempotence,
 * financial / PLUS invariants, dashboard numbers and the whole admin panel
 * rendered on the demo data. Runs on SQLite and on MySQL 8.
 */
class WaffrliDemoSeederTest extends AdminPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function seedDemo(): void
    {
        $this->seed(WaffrliDemoSeeder::class);
    }

    /** @return array<string, int> */
    protected function rowCounts(): array
    {
        $tables = Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false);

        return collect($tables)->reject(fn ($t) => in_array($t, ['migrations', 'sqlite_sequence'], true))
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->sortKeys()->all();
    }

    public function test_demo_seeder_refuses_production_and_real_data(): void
    {
        // Called directly: `db:seed` would itself ask for confirmation in production.
        $run = fn () => (new WaffrliDemoSeeder)->setContainer($this->app)->__invoke();

        $this->app['env'] = 'production';
        try {
            $run();
            $this->fail('The demo seeder must refuse production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may only be seeded in', $e->getMessage());
        }
        $this->assertSame(0, User::count());

        $this->app['env'] = 'staging';
        $this->expectException(RuntimeException::class);
        $run();
    }

    public function test_demo_seeder_refuses_a_database_with_real_orders(): void
    {
        $this->seedDemo();
        Order::query()->where('order_number', 'DEMO-0001')->update(['order_number' => 'REAL-0001']);

        $this->expectExceptionMessage('non-demo orders');
        $this->seedDemo();
    }

    public function test_essential_seeder_never_invents_currencies_or_overwrites(): void
    {
        $this->seed(WaffrliEssentialSeeder::class);
        $this->assertSame(0, Currency::count(), 'No currency without explicit configuration.');
        $this->assertSame(2, DB::table('payment_methods')->count());
        $this->assertTrue(DB::table('settings')->where('key', 'operations.timezone')->exists());
        $this->assertNotNull(Role::findByName('dispatcher', 'admin'));

        $dispatcher = Role::findByName('dispatcher', 'admin');
        $dispatcher->syncPermissions(['orders.view']);
        config(['waffrli.seed.default_currency' => 'SYP', 'waffrli.seed.extra_currencies' => 'USD:0.000077:2']);
        $this->seed(WaffrliEssentialSeeder::class);
        $this->seed(WaffrliEssentialSeeder::class);

        $this->assertSame(['orders.view'], $dispatcher->fresh()->permissions->pluck('name')->all(), 'Edited roles are never overwritten.');
        $this->assertSame('SYP', Currency::query()->default()->value('code'));
        $this->assertSame('0.000077000000', (string) Currency::where('code', 'USD')->value('exchange_rate'));

        config(['waffrli.seed.default_currency' => 'EUR']);
        $this->seed(WaffrliEssentialSeeder::class);
        $this->assertSame('SYP', Currency::query()->default()->value('code'), 'An existing default is never switched.');
        $this->assertSame(count(AdminRoleTemplatesSeeder::templates()) + 1, Role::count());
    }

    public function test_demo_data_is_idempotent_identifiable_and_consistent(): void
    {
        $this->seedDemo();
        $first = $this->rowCounts();
        $this->seedDemo();
        $this->assertSame($first, $this->rowCounts(), 'A second run must not add or remove any row.');

        // Identifiable, no usable credentials, privileged operator retired.
        $this->assertSame(0, User::query()->where('email', 'not like', '%@'.DemoContext::DEMO_EMAIL_DOMAIN)->count());
        $this->assertSame(0, Order::query()->where('order_number', 'not like', 'DEMO-%')->count());
        $operator = Admin::query()->where('email', DemoContext::OPERATOR_EMAIL)->firstOrFail();
        $this->assertFalse($operator->is_active);
        $this->assertFalse($operator->hasRole(Admin::SUPER_ADMIN_ROLE));
        $this->assertSame(1, Admin::count(), 'No other admin account is created.');
        $this->assertTrue(AdminAuditLog::query()->get()->every(fn ($log) => ($log->properties['generated_demo'] ?? false) === true));

        // Coverage of every order status, both order types, urgent and several currencies.
        $statuses = Order::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();
        $this->assertEqualsCanonicalizing(array_map(fn ($s) => $s->value, OrderStatus::cases()), $statuses);
        $this->assertTrue(Order::query()->where('type', Order::TYPE_SCHEDULED)->where('status', 'pending')->whereNotNull('urgent_at')->exists());
        $this->assertTrue(Order::query()->where('type', Order::TYPE_SCHEDULED)->where('status', 'pending')->whereNull('urgent_at')->exists());
        $this->assertGreaterThanOrEqual(3, Order::query()->distinct()->count('currency_id'));

        // Financial invariants.
        foreach (Order::with('payments', 'items.options')->get() as $order) {
            $this->assertNotNull(DB::table('order_status_histories')->where('order_id', $order->id)->whereNull('from_status')->first(), "{$order->order_number} lacks its submission history row.");
            $this->assertSame((string) $order->total, $this->sum($order->payments->pluck('amount')), "{$order->order_number}: payments must equal the total.");
            if ($order->status === OrderStatus::Delivered) {
                $this->assertSame((string) $order->total, $this->sum($order->payments->pluck('collected_amount')), "{$order->order_number}: delivered orders are fully collected.");
            }
            $this->assertSame($this->sum($order->items->pluck('line_total')), Money::toStorage(Money::of((string) $order->subtotal)->minus((string) $order->offer_discount_total)), "{$order->order_number}: lines add up.");
        }
        foreach (OrderPayment::all() as $payment) {
            $this->assertTrue($payment->returnableAmount()->isGreaterThanOrEqualTo(0));
        }
        $ledger = app(WalletLedger::class);
        $this->assertGreaterThanOrEqual(3, Wallet::count());
        foreach (Wallet::all() as $wallet) {
            $this->assertTrue($ledger->isConsistent($wallet), "Wallet {$wallet->id} differs from its ledger.");
        }
        $this->assertSame(0, DB::table('wallet_transactions')->whereNotIn('type', ['order_payment', 'order_payment_reversal', 'order_refund'])->count());

        // PLUS: all cycle states, snapshot differences and free-delivery accounting.
        $this->assertEqualsCanonicalizing(['active', 'expired', 'scheduled'], PlusCycle::query()->distinct()->pluck('status')->all());
        foreach (PlusCycle::all() as $cycle) {
            $usedByOrders = Order::query()->where('plus_cycle_id', $cycle->id)->where('free_delivery_applied', true)
                ->whereDoesntHave('cancellation', fn ($q) => $q->where('free_delivery_restored', true))->count();
            $this->assertSame($usedByOrders, $cycle->free_deliveries_used, "Cycle {$cycle->id}: used free deliveries match orders.");
        }
        $member = User::query()->where('email', DemoContext::email('customer-01'))->firstOrFail();
        [$current, $next] = PlusCycle::query()->where('user_id', $member->id)->orderBy('cycle_number')->get()->all();
        $this->assertSame(5, $current->free_deliveries_total);
        $this->assertSame(6, $next->free_deliveries_total, 'The override applies from the next cycle only.');
        $this->assertSame(3, PlusCycleDiscount::query()->where('plus_cycle_id', $current->id)->count());
        $this->assertSame(['exception', 'plan'], PlusCycleDiscount::query()->where('plus_cycle_id', $next->id)->orderBy('source')->pluck('source')->unique()->values()->all());

        // Catalog: one image per product, several categories, hidden ratings for moderation.
        $this->assertTrue(Product::all()->every(fn ($p) => $p->image && Storage::disk('public')->exists($p->image)));
        $this->assertGreaterThan(0, Product::has('categories', '>=', 2)->count());
        $this->assertSame(2, DB::table('product_ratings')->where('is_hidden', true)->count());
        foreach (Product::all() as $product) {
            $visible = DB::table('product_ratings')->where('product_id', $product->id)->where('is_hidden', false);
            $this->assertSame((clone $visible)->count(), $product->rating_count);
            $this->assertSame((int) (clone $visible)->sum('rating'), $product->rating_sum);
        }
    }

    public function test_seeded_translatable_data_is_bilingual(): void
    {
        $this->seedDemo();
        $checked = 0;
        foreach (DatabaseDocsCommand::translatableColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                foreach (DB::table($table)->whereNotNull($column)->pluck($column, 'id') as $id => $raw) {
                    $value = json_decode((string) $raw, true);
                    $this->assertIsArray($value, "{$table}#{$id}.{$column} is not translatable JSON");
                    foreach (['ar', 'en'] as $locale) {
                        $this->assertNotSame('', trim((string) ($value[$locale] ?? '')), "{$table}#{$id}.{$column} has no {$locale} text");
                    }
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(50, $checked, 'Demo data must exercise the translatable columns.');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) DB::table('cities')->value('name'));
    }

    public function test_dashboard_shows_database_numbers(): void
    {
        $this->seedDemo();
        $this->actingAsAdmin($this->superAdmin());

        $this->get('/admin')->assertOk();
        Livewire::test(PlatformOverview::class)
            ->assertSee(__('admin.dashboard_stats.customers'))->assertSee((string) User::count())
            ->assertSee(Store::count().' / '.Branch::count())
            ->assertSee(__('admin.dashboard_stats.plus_active'));
        Livewire::test(OperationsOverview::class)
            ->assertSee(__('admin.dashboard.urgent_orders'))
            ->assertSee((string) Order::whereIn('status', OrderStatus::active())->count());
        Livewire::test(OrdersByStatusChart::class)->assertSuccessful();

        $counts = OrdersByStatusChart::counts();
        $this->assertSame(Order::count(), array_sum($counts));
        $this->assertSame(Order::where('status', 'delivered')->count(), $counts['delivered']);

        $rows = collect(FinanceOverview::rows())->keyBy('code');
        $this->assertTrue($rows->every(fn ($row) => $row['ledger_consistent']));
        $this->assertSame(
            (string) Money::toStorage((string) Wallet::where('currency_id', Currency::where('code', 'USD')->value('id'))->sum('balance')),
            $rows['USD']['wallet_balances']
        );
        Livewire::test(FinanceOverview::class)->assertSee('USD')->assertSee('SYP')->assertSee('EUR');
    }

    public function test_every_panel_screen_renders_on_demo_data(): void
    {
        $this->seedDemo();
        $this->actingAsAdmin($this->superAdmin());

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            $this->get($page::getUrl())->assertOk();
        }

        $checked = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
            $pages = $resource::getPages();
            $pageClass = collect($pages)->map(fn ($page) => $page->getPage())
                ->first(fn ($class) => is_subclass_of($class, ViewRecord::class) || is_subclass_of($class, EditRecord::class));

            // Up to three records per resource, so different states are rendered.
            foreach ($resource::getEloquentQuery()->limit(3)->get() as $record) {
                foreach (['view', 'edit'] as $name) {
                    if (array_key_exists($name, $pages) && $resource::can($name, $record)) {
                        $this->get($resource::getUrl($name, ['record' => $record]))->assertOk();
                        $checked++;
                    }
                }
                foreach ($resource::getRelations() as $relationManager) {
                    $relationManager = is_string($relationManager) ? $relationManager : $relationManager->relationManager;
                    if ($pageClass && $relationManager::canViewForRecord($record, $pageClass)) {
                        Livewire::test($relationManager, ['ownerRecord' => $record, 'pageClass' => $pageClass])->assertSuccessful();
                        $checked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(80, $checked);
    }

    protected function sum($values): string
    {
        return Money::toStorage(collect($values)->reduce(fn ($carry, $v) => $carry->plus((string) $v), BigDecimal::zero()));
    }
}
