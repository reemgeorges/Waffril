<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoHamaSeeder;
use Database\Seeders\Demo\DemoHomsSeeder;
use Database\Seeders\Demo\DemoPlatformSeeder;
use Illuminate\Database\Seeder;

/**
 * Adds demo REFERENCE DATA and photographs for Flutter catalog/filter testing.
 *
 * Safe next to real test orders: unlike WaffrliDemoSeeder, this never invokes
 * DemoOrderSeeder, DemoPeopleSeeder, DemoPlusSeeder, DemoEngagementSeeder.
 * Regional seeders decide independently whether demonstration transactions
 * can be added; when real orders exist they only add reference data.
 *
 *   php artisan db:seed --class="Database\\Seeders\\WaffrliFlutterCatalogSeeder"
 */
class WaffrliFlutterCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DemoContext::assertSafeEnvironment();
        $this->call(WaffrliEssentialSeeder::class);

        WaffrliDemoSeeder::inDemoEnvironment(function (): void {
            $this->call(DemoPlatformSeeder::class);
            $this->call(DemoCatalogSeeder::class);
            $this->call(DemoHamaSeeder::class);
            $this->call(DemoHomsSeeder::class);
        });

        $this->call(WaffrliDemoPhotosSeeder::class);
    }
}
