<?php

namespace Database\Seeders\Demo;

use App\Models\Driver;

/**
 * WAFFRLI demo data (2026-11-16) — HOMS, a smaller second demonstration city
 * (see DemoRegionSeeder for the rules; Hama is the main one).
 *
 *   php artisan db:seed --class="Database\Seeders\Demo\DemoHomsSeeder"
 *
 * Geography (data/homs.json, OpenStreetMap, ODbL): OSM has no Homs city
 * boundary, so the whole service area is an APPROXIMATE 7.5 km radius
 * around the OSM city node (labelled as such). Five neighbourhoods with a
 * real OSM administrative boundary are VERIFIED areas; ten more OSM
 * neighbourhood/suburb nodes give APPROXIMATE nearest-centre areas.
 * Every store is fictional; prices are in NEW Syrian pounds.
 */
class DemoHomsSeeder extends DemoRegionSeeder
{
    protected function dataFile(): string
    {
        return 'homs.json';
    }

    protected function orderPrefix(): string
    {
        return 'DEMO-HOMS-';
    }

    protected function customerPhoneOffset(): int
    {
        return 50;   // +1 202 555 0151 …
    }

    protected function driverPhoneOffset(): int
    {
        return 150;  // +1 202 555 0251 …
    }

    protected function feeBands(): array
    {
        return [
            [0, 3, '80', '10'],
            [3, 7, '110', '12'],
            [7, 20, '158', '14'],
        ];
    }

    protected function stores(): array
    {
        return [
            'demo-homs-clock-tower-grill' => ['مشاوي الساعة', 'Clock Tower Grill', ['restaurants'], 41, null, [
                'inshaat' => ['فرع الإنشاءات (٢٤ ساعة)', "Al-Insha'at branch (24 h)", 'al-inshaat', 80, 60, 'always'],
                'waer' => ['فرع الوعر', "Wa'er branch", 'waer', 50, -40, 'restaurant'],
            ], [
                'chicken-shawarma' => ['سندويش شاورما دجاج', 'Chicken shawarma sandwich', '250', ['demo-sandwiches'], ['bread', 'sauce'], '#b45309'],
                'kebab' => ['صحن كباب', 'Kebab plate', '750', ['demo-grills'], ['sauce'], '#9a3412'],
                'fattoush' => ['فتوش', 'Fattoush salad', '175', ['demo-food'], [], '#4d7c0f'],
                'ayran' => ['عيران', 'Ayran', '60', ['demo-beverages', 'demo-dairy'], [], '#0369a1'],
            ]],
            'demo-homs-daily-market' => ['سوق اليوم', 'Daily Market', ['supermarkets'], 42, '300', [
                'ghouta-road' => ['فرع المحطة', 'Al-Mahatta branch', 'al-mahatta', -40, 50, 'market'],
            ], [
                'milk' => ['حليب ١ لتر', 'Milk 1 L', '120', ['demo-dairy'], [], '#2563eb'],
                'eggs' => ['بيض ٣٠ حبة', 'Eggs, 30 pieces', '650', ['demo-groceries'], [], '#eab308'],
                'rice' => ['رز ١ كغ', 'Rice 1 kg', '180', ['demo-groceries'], [], '#a3a3a3'],
                'water' => ['مياه معدنية ٦ عبوات', 'Mineral water 6-pack', '150', ['demo-beverages'], [], '#0ea5e9'],
            ]],
            'demo-homs-orontes-pharmacy' => ['صيدلية الضفة', 'Riverbank Pharmacy', ['pharmacies'], 43, null, [
                'qosour' => ['فرع القصور (٢٤ ساعة)', 'Qosour branch (24 h)', 'qosour', 30, 30, 'always'],
            ], [
                'paracetamol' => ['باراسيتامول ٥٠٠ ملغ', 'Paracetamol 500 mg', '60', ['demo-medicine'], [], '#16a34a'],
                'vitamin-c' => ['فيتامين سي ١٠٠٠ ملغ', 'Vitamin C 1000 mg', '220', ['demo-medicine'], [], '#f97316'],
                'sanitizer' => ['معقم يدين', 'Hand sanitizer', '90', ['demo-personal-care'], [], '#14b8a6'],
            ]],
        ];
    }

    protected function customers(): array
    {
        return [
            1 => ['لؤي', 'الأتاسي', 'ar', [['البيت', 'Home', 'al-inshaat', -60, 40, 'الإنشاءات', "Al-Insha'at"]]],
            2 => ['رنا', 'السباعي', 'en', [['البيت', 'Home', 'waer', 60, 50, 'الوعر', "Wa'er"]]],
            3 => ['مهند', 'الجندي', 'ar', [['البيت', 'Home', 'karm-al-shami', 40, -30, 'كرم الشامي', 'Karm Al-Shami']]],
        ];
    }

    protected function drivers(): array
    {
        return [
            1 => ['طارق الدروبي', 'al-inshaat', 'motorcycle', 'DEMO-HS-501', Driver::AVAILABILITY_AVAILABLE, 120, 90],
            2 => ['فادي الخوري', 'qosour', 'car', 'DEMO-HS-502', Driver::AVAILABILITY_AVAILABLE, -60, 70],
        ];
    }

    protected function scenarios(): array
    {
        $grill = 'demo-homs-clock-tower-grill';
        $market = 'demo-homs-daily-market';
        $pharmacy = 'demo-homs-orontes-pharmacy';

        return [
            '0001' => [[-3, 19, 0], 1, $grill, 'waer', [['kebab', 1, ['garlic']], ['ayran', 1, []]], [], [[5, 'accept'], [10, 'assign', 1], [25, 'pickup'], [45, 'deliver']]],
            '0002' => [[-2, 18, 30], 2, $market, 'ghouta-road', [['milk', 2, []], ['eggs', 1, []]], [], [[5, 'accept'], [10, 'assign', 2], [25, 'pickup'], [45, 'deliver']]],
            '0003' => [[-1, 19, 0], 3, $grill, 'waer', [['chicken-shawarma', 2, ['saj']]], [], [[3, 'cancel_customer', 'Ordered by mistake']]],
            '0004' => [-15, 1, $pharmacy, 'qosour', [['paracetamol', 1, []], ['sanitizer', 1, []]], [], [[3, 'accept'], [8, 'assign', 2]]],
        ];
    }
}
