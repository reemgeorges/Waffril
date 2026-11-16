<?php

namespace Database\Seeders;

use App\Models\Currency;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoEngagementSeeder;
use Database\Seeders\Demo\DemoHamaSeeder;
use Database\Seeders\Demo\DemoHomsSeeder;
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

        self::inDemoEnvironment(function () {
            $this->call(DemoPlatformSeeder::class);
            // Demo history spans several currencies (USD, SYP, EUR orders and
            // wallets), so every active currency is payable while seeding; the
            // launch policy (default currency only) is restored afterwards.
            config(['waffrli.currency.customer_currencies' => Currency::query()->where('is_active', true)->pluck('code')->all()]);
            $this->call([
                DemoCatalogSeeder::class,
                DemoPeopleSeeder::class,
                DemoPlusSeeder::class,
                DemoOrderSeeder::class,
                DemoEngagementSeeder::class,
                // 2026-11-16: the main demonstration cities for the apps.
                DemoHamaSeeder::class,
                DemoHomsSeeder::class,
            ]);
        });
    }

    /**
     * Runs $callback with one DemoContext and with broadcasting and push
     * muted, then restores the configuration. Re-entrant: nested calls (a
     * regional seeder inside this seeder) reuse the outer context.
     */
    public static function inDemoEnvironment(callable $callback): mixed
    {
        if (app()->bound(DemoContext::class) && config('waffrli.notifications_muted')) {
            return $callback(app(DemoContext::class));
        }

        $context = app()->instance(DemoContext::class, new DemoContext);
        // Demo data never reaches real devices or a running WebSocket server:
        // broadcasting and push are muted while seeding, restored afterwards.
        $saved = [
            'broadcasting.default' => config('broadcasting.default'),
            'waffrli.push.enabled' => config('waffrli.push.enabled'),
            'waffrli.notifications_muted' => config('waffrli.notifications_muted'),
            'waffrli.currency.customer_currencies' => config('waffrli.currency.customer_currencies'),
        ];
        config(['broadcasting.default' => 'null', 'waffrli.push.enabled' => false, 'waffrli.notifications_muted' => true]);

        try {
            return $callback($context);
        } finally {
            $context->retireOperator();
            config($saved);
            app()->forgetInstance(DemoContext::class);
        }
    }
}
