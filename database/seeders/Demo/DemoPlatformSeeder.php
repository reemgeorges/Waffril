<?php

namespace Database\Seeders\Demo;

use App\Models\City;
use App\Models\Currency;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Models\Setting;
use App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService;
use Database\Seeders\Essential\ConfiguredCurrencySeeder;
use Illuminate\Database\Seeder;

/**
 * WAFFRLI demo — currencies with DEMONSTRATION rates, cities, delivery
 * areas, platform-wide fee bands and a few settings. Rows are matched by
 * natural keys and never overwritten.
 */
class DemoPlatformSeeder extends Seeder
{
    /** Demonstration rates (units per 1 USD). Not market data. */
    public const DEMO_CURRENCIES = [
        'SYP' => ['rate' => '13000', 'decimals' => 0],
        'EUR' => ['rate' => '0.92', 'decimals' => 2],
    ];

    /** name => [ar, en, lat, lng, radius_km, areas: key => [ar, en, [minLng, minLat, maxLng, maxLat]]] */
    public const CITIES = [
        'damascus' => ['دمشق', 'Damascus', 33.5138, 36.2765, 18, [
            'west' => ['المزة وكفرسوسة', 'Mazzeh & Kafr Sousa', [36.2150, 33.4880, 36.2800, 33.5300]],
            'center' => ['وسط المدينة', 'City center', [36.2800, 33.4950, 36.3250, 33.5300]],
            'north' => ['المهاجرين والصالحية', 'Muhajireen & Salhiyeh', [36.2500, 33.5300, 36.3300, 33.5650]],
        ]],
        'aleppo' => ['حلب', 'Aleppo', 36.2021, 37.1343, 15, [
            'center' => ['حلب الوسطى', 'Aleppo center', [37.1000, 36.1800, 37.1800, 36.2300]],
        ]],
        'homs' => ['حمص', 'Homs', 34.7324, 36.7137, 12, []],
    ];

    public function run(): void
    {
        $context = app(DemoContext::class);
        $admin = $context->operator();

        $context->at($context->anchor()->subDays(30), function () use ($admin) {
            // Demonstration rates are per 1 USD, so they are only added when USD is (or becomes) the default.
            $default = Currency::query()->default()->value('code') ?? 'USD';
            (new ConfiguredCurrencySeeder(['default' => $default, 'extra' => $default === 'USD' ? self::DEMO_CURRENCIES : []]))->run();

            foreach (self::CITIES as $key => [$ar, $en, $lat, $lng, $radius, $areas]) {
                $city = City::query()->where('name->en', $en)->first()
                    ?? City::create(['name' => ['ar' => $ar, 'en' => $en], 'center_lat' => $lat, 'center_lng' => $lng, 'radius_km' => $radius, 'is_active' => true]);

                foreach ($areas as $areaKey => [$areaAr, $areaEn, [$minLng, $minLat, $maxLng, $maxLat]]) {
                    DeliveryArea::query()->where('city_id', $city->id)->where('name->en', $areaEn)->exists()
                        || DeliveryArea::create([
                            'city_id' => $city->id,
                            'name' => ['ar' => $areaAr, 'en' => $areaEn],
                            'boundary' => ['type' => 'Polygon', 'coordinates' => [[
                                [$minLng, $minLat], [$maxLng, $minLat], [$maxLng, $maxLat], [$minLng, $maxLat], [$minLng, $minLat],
                            ]]],
                            'is_active' => true,
                        ]);
                }
            }

            $this->feeRules($admin->id);

            foreach (['support.phone' => DemoContext::phone(90), 'support.whatsapp' => DemoContext::phone(91), 'general.platform_name' => 'WAFFRLI (demo)'] as $key => $value) {
                Setting::query()->where('key', $key)->exists() || Setting::create(['key' => $key, 'value' => $value]);
            }
        });
    }

    /** Platform-wide bands: global fallback, per-city bands, and one cheaper area band. */
    protected function feeRules(int $adminId): void
    {
        $damascus = City::query()->where('name->en', 'Damascus')->firstOrFail();
        $aleppo = City::query()->where('name->en', 'Aleppo')->firstOrFail();
        $center = DeliveryArea::query()->where('city_id', $damascus->id)->where('name->en', 'City center')->firstOrFail();

        $rules = [
            [null, null, 0, 5, '1.00', '0.20'],
            [null, null, 5, 15, '1.50', '0.25'],
            [$damascus->id, null, 0, 3, '0.75', '0.15'],
            [$damascus->id, null, 3, 10, '1.00', '0.20'],
            [$damascus->id, null, 10, 25, '2.00', '0.25'],
            [$damascus->id, $center->id, 0, 10, '0.50', '0.10'],
            [$aleppo->id, null, 0, 8, '0.90', '0.18'],
            [$aleppo->id, null, 8, 20, '1.60', '0.22'],
        ];

        $service = app(DeliveryFeeRuleService::class);
        foreach ($rules as [$cityId, $areaId, $from, $to, $base, $perKm]) {
            $exists = DeliveryFeeRule::query()->where('city_id', $cityId)->where('delivery_area_id', $areaId)
                ->where('distance_from_km', $from)->exists();
            if (! $exists) {
                $service->persist([
                    'city_id' => $cityId, 'delivery_area_id' => $areaId,
                    'distance_from_km' => $from, 'distance_to_km' => $to,
                    'base_fee' => $base, 'per_km_fee' => $perKm, 'is_active' => true,
                ], null, $adminId);
            }
        }
    }
}
