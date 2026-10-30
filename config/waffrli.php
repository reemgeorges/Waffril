<?php

/**
 * WAFFRLI (2026-11-01) — seeding configuration.
 *
 * The essential seeders never invent business data: the default currency
 * and every extra currency (with its exchange rate) are created only when
 * they are explicitly configured here. Rates are "units per 1 default
 * unit" and are entered by an operator, never fetched or guessed.
 *
 *   WAFFRLI_DEFAULT_CURRENCY=USD
 *   WAFFRLI_EXTRA_CURRENCIES="SYP:13000:0,EUR:0.92:2"   (code:rate:decimals)
 */
return [
    'seed' => [
        'default_currency' => env('WAFFRLI_DEFAULT_CURRENCY'),
        'extra_currencies' => env('WAFFRLI_EXTRA_CURRENCIES'),
    ],

    /** Known display metadata for configured currency codes. */
    'currency_catalogue' => [
        'USD' => ['name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي'], 'symbol' => '$', 'decimals' => 2],
        'SYP' => ['name' => ['en' => 'Syrian Pound', 'ar' => 'ليرة سورية'], 'symbol' => 'ل.س', 'decimals' => 0],
        'EUR' => ['name' => ['en' => 'Euro', 'ar' => 'يورو'], 'symbol' => '€', 'decimals' => 2],
        'TRY' => ['name' => ['en' => 'Turkish Lira', 'ar' => 'ليرة تركية'], 'symbol' => '₺', 'decimals' => 2],
    ],

    'driver_map' => [
        // A stored position older than this is shown as stale on the map.
        'stale_after_minutes' => (int) env('WAFFRLI_DRIVER_LOCATION_STALE_MINUTES', 10),
        'refresh_seconds' => (int) env('WAFFRLI_DRIVER_MAP_REFRESH_SECONDS', 30),
        // OpenStreetMap standard tiles. Their usage policy requires the
        // attribution below and forbids heavy use: for production traffic
        // configure your own tile server or a commercial provider here.
        'tile_url' => env('WAFFRLI_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'tile_attribution' => env('WAFFRLI_MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'),
    ],
];
