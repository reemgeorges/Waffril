<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI P0 (2026-10-30) — DEVELOPMENT-ONLY currency bootstrap, aligned
 * with the P0 `currencies` schema (exchange_rate + single default).
 *
 * Not called from DatabaseSeeder and refuses to run in production: real
 * currencies, the real default and real exchange rates are business data
 * an admin enters through the currency endpoints.
 *
 * - USD is created and, only if NO default exists yet, initialized as the
 *   default (rate 1). An existing default is never changed.
 * - SYP is created only when DEV_SYP_EXCHANGE_RATE (SYP per 1 default
 *   unit) is provided, so no exchange rate is ever invented here.
 *
 * Idempotent: rerunning it updates nothing that already exists.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('CurrencySeeder is development-only and was skipped in production.');

            return;
        }

        $usd = Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'],
                'symbol' => '$',
                'decimals' => 2,
                'exchange_rate' => '1',
                'is_active' => true,
            ]
        );

        if (! Currency::query()->default()->exists()) {
            $usd->forceFill(['is_default' => true, 'is_active' => true, 'exchange_rate' => '1'])->save();
        }

        $sypRate = env('DEV_SYP_EXCHANGE_RATE');
        if ($sypRate === null || $sypRate === '') {
            $this->command?->info('SYP not seeded: set DEV_SYP_EXCHANGE_RATE to seed it with an explicit development rate.');

            return;
        }

        Currency::firstOrCreate(
            ['code' => 'SYP'],
            [
                'name' => ['en' => 'Syrian Pound', 'ar' => 'ليرة سورية'],
                'symbol' => 'ل.س',
                'decimals' => 2,
                'exchange_rate' => (string) $sypRate,
                'is_active' => true,
            ]
        );
    }
}
