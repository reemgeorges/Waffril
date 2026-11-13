<?php

namespace Database\Seeders;

use App\Models\Currency;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoEngagementSeeder;
use Database\Seeders\Demo\DemoOrderSeeder;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Database\Seeders\Demo\DemoPlatformSeeder;
use Database\Seeders\Demo\DemoPlusSeeder;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI (2026-11-01) — realistic, relationally consistent SAMPLE data for
 * the whole admin panel, for LOCAL / DEMO databases only.
 *
 *   php artisan db:seed --class=WaffrliDemoSeeder
 *
 * - Refuses to run outside local/development/demo/testing, and on databases
 *   that already hold non-demo orders or wallet movements.
 * - Runs WaffrliEssentialSeeder first, then the demo modules in dependency
 *   order. Deterministic and idempotent: every record is keyed by a demo
 *   identifier and skipped when it exists; nothing is deleted or overwritten.
 * - Creates no usable login: create your own Super Admin with
 *   `php artisan waffrli:create-super-admin`.
 * - Relative dates are computed from the moment of the FIRST run; seed a
 *   fresh demo database to move them to "now" again.
 */
class WaffrliDemoSeeder extends Seeder
{
    public function run(): void
    {
        DemoContext::assertSafeToSeed();

        $this->call(WaffrliEssentialSeeder::class);

        $context = app()->instance(DemoContext::class, new DemoContext);

        // Demo data never reaches real devices or a running WebSocket server:
        // broadcasting and push are muted while seeding, restored afterwards.
        $broadcaster = config('broadcasting.default');
        $push = config('waffrli.push.enabled');
        $muted = config('waffrli.notifications_muted');
        // Demo history spans several currencies (USD, SYP, EUR orders and
        // wallets), so every active currency is payable while seeding; the
        // launch policy (default currency only) is restored afterwards.
        $customerCurrencies = config('waffrli.currency.customer_currencies');
        config(['broadcasting.default' => 'null', 'waffrli.push.enabled' => false, 'waffrli.notifications_muted' => true,
        ]);

        try {
            $this->call(DemoPlatformSeeder::class);
            config(['waffrli.currency.customer_currencies' => Currency::query()->where('is_active', true)->pluck('code')->all()]);
            $this->call([
                DemoCatalogSeeder::class,
                DemoPeopleSeeder::class,
                DemoPlusSeeder::class,
                DemoOrderSeeder::class,
                DemoEngagementSeeder::class,
            ]);
        } finally {
            $context->retireOperator();
            config(['broadcasting.default' => $broadcaster, 'waffrli.push.enabled' => $push, 'waffrli.notifications_muted' => $muted,
                'waffrli.currency.customer_currencies' => $customerCurrencies]);
        }
    }
}
