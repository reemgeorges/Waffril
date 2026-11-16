<?php

namespace Database\Seeders\Demo;

use App\Models\Driver;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Services\Plus\PlusService;

/**
 * WAFFRLI demo data (2026-11-16) — HAMA, the main demonstration city for the
 * customer and driver apps (see DemoRegionSeeder for the rules).
 *
 *   php artisan db:seed --class="Database\Seeders\Demo\DemoHamaSeeder"
 *
 * Geography (data/hama.json, OpenStreetMap, ODbL): the OSM city boundary
 * (relation 18354844) and 32 named centres inside it — 19 place=neighbourhood
 * nodes, one mapped neighbourhood way, 6 named residential areas and 6
 * villages within the boundary. Delivery areas = one verified whole-city
 * service area plus 32 APPROXIMATE nearest-centre areas.
 *
 * Every store is FICTIONAL (clearly marked as demo); no real merchant is
 * represented. Prices are in NEW Syrian pounds.
 */
class DemoHamaSeeder extends DemoRegionSeeder
{
    protected function dataFile(): string
    {
        return 'hama.json';
    }

    protected function orderPrefix(): string
    {
        return 'DEMO-HAMA-';
    }

    protected function customerPhoneOffset(): int
    {
        return 30;   // +1 202 555 0131 …
    }

    protected function driverPhoneOffset(): int
    {
        return 130;  // +1 202 555 0231 …
    }

    /** City-wide bands in NEW SYP (continuous at every band edge). */
    protected function feeBands(): array
    {
        return [
            [0, 3, '75', '10'],
            [3, 6, '105', '12'],
            [6, 12, '141', '14'],
            [12, 25, '225', '15'],
        ];
    }

    /** The historic centre (Az Zanbaqi) has its own, cheaper band: the area scope wins there. */
    protected function areaFeeBands(): array
    {
        return ['zanbaqi' => [[0, 25, '60', '8']]];
    }

    protected function stores(): array
    {
        return [
            'demo-hama-orontes-grill' => ['مشاوي العاصي', 'Orontes Grill', ['restaurants'], 21, null, [
                // Open around the clock: the "live" demo orders use this branch at any hour.
                'zanbaqi' => ['فرع الزنبقي (٢٤ ساعة)', 'Az Zanbaqi branch (24 h)', 'zanbaqi', 120, -80, 'always'],
                'mashtal' => ['فرع غرب المشتل', 'Gharb Al Mashtal branch', 'gharb-al-mashtal', 60, 90, 'restaurant'],
            ], [
                'chicken-shawarma' => ['سندويش شاورما دجاج', 'Chicken shawarma sandwich', '250', ['demo-sandwiches'], ['bread', 'sauce'], '#b45309'],
                'beef-shawarma' => ['سندويش شاورما لحمة', 'Beef shawarma sandwich', '320', ['demo-sandwiches'], ['bread', 'sauce'], '#92400e'],
                'shawarma-plate' => ['صحن شاورما دجاج', 'Chicken shawarma plate', '550', ['demo-grills', 'demo-food'], ['sauce'], '#c2410c'],
                'kebab' => ['صحن كباب حموي', 'Hamawi kebab plate', '750', ['demo-grills'], ['sauce'], '#9a3412'],
                'mixed-grill' => ['مشاوي مشكلة', 'Mixed grill platter', '1200', ['demo-grills'], [], '#7c2d12'],
                'hummus' => ['صحن حمص', 'Hummus plate', '150', ['demo-food'], [], '#a16207'],
                'fattoush' => ['فتوش', 'Fattoush salad', '175', ['demo-food'], [], '#4d7c0f'],
                'ayran' => ['عيران', 'Ayran', '60', ['demo-beverages', 'demo-dairy'], [], '#0369a1'],
            ]],
            'demo-hama-halawa-corner' => ['ركن حلاوة الجبن', 'Halawet El Jibn Corner', ['bakeries'], 22, null, [
                'madinah' => ['فرع المدينة', 'Al-Madinah branch', 'al-madinah', -50, 40, 'market'],
            ], [
                'halawa' => ['حلاوة الجبن', 'Halawet el jibn', '600', ['demo-sweets'], ['box'], '#f59e0b'],
                'baklava' => ['بقلاوة بالفستق', 'Pistachio baklava', '900', ['demo-sweets'], ['box'], '#65a30d'],
                'maamoul' => ['علبة معمول بالتمر', 'Date maamoul box', '400', ['demo-sweets', 'demo-bakery'], [], '#78350f'],
                'namoura' => ['نمورة', 'Namoura', '350', ['demo-sweets'], [], '#d97706'],
            ]],
            'demo-hama-golden-sheaf' => ['فرن السنابل الذهبية', 'Golden Sheaf Bakery', ['bakeries'], 23, null, [
                'hamidiyah' => ['فرع الحميدية', 'Al Hamidiyah branch', 'hamidiyah', 40, 60, 'bakery'],
                'nasr' => ['فرع النصر', 'Nasr branch', 'nasr', -30, 50, 'bakery'],
            ], [
                'zaatar' => ['منقوشة زعتر', 'Zaatar manakish', '40', ['demo-bakery'], [], '#65a30d'],
                'cheese-manakish' => ['منقوشة جبنة', 'Cheese manakish', '70', ['demo-bakery'], [], '#ca8a04'],
                'lahm-bi-ajeen' => ['لحم بعجين', 'Lahm bi ajeen', '60', ['demo-bakery'], [], '#b91c1c'],
                'spinach' => ['فطيرة سبانخ', 'Spinach fatayer', '45', ['demo-bakery'], [], '#15803d'],
                'kaak' => ['ربطة كعك', 'Kaak bundle', '100', ['demo-bakery', 'demo-snacks'], [], '#a16207'],
                'bread' => ['ربطة خبز سياحي', 'Pita bread bag', '35', ['demo-bakery', 'demo-groceries'], [], '#d6a35c'],
            ]],
            'demo-hama-fresh-basket' => ['السلة الطازجة', 'Fresh Basket Market', ['supermarkets'], 24, '300', [
                'shahba' => ['فرع الشهباء', 'Ash Shahba branch', 'shahba', 70, -60, 'market'],
                'andalus' => ['فرع الأندلس', 'Al-Andalus branch', 'andalus', -40, 70, 'market'],
            ], [
                'milk' => ['حليب ١ لتر', 'Milk 1 L', '120', ['demo-dairy'], [], '#2563eb'],
                'labneh' => ['لبنة ٥٠٠ غ', 'Labneh 500 g', '180', ['demo-dairy'], [], '#0891b2'],
                'eggs' => ['بيض ٣٠ حبة', 'Eggs, 30 pieces', '650', ['demo-groceries'], [], '#eab308'],
                'rice' => ['رز ١ كغ', 'Rice 1 kg', '180', ['demo-groceries'], [], '#a3a3a3'],
                'sugar' => ['سكر ١ كغ', 'Sugar 1 kg', '140', ['demo-groceries'], [], '#e5e7eb'],
                'olive-oil' => ['زيت زيتون ١ لتر', 'Olive oil 1 L', '750', ['demo-groceries'], [], '#4d7c0f'],
                'water' => ['مياه معدنية ٦ عبوات', 'Mineral water 6-pack', '150', ['demo-beverages'], [], '#0ea5e9'],
                'tea' => ['شاي ٢٥٠ غ', 'Tea 250 g', '220', ['demo-beverages', 'demo-groceries'], [], '#7f1d1d'],
                'chips' => ['شيبس', 'Potato chips', '50', ['demo-snacks'], [], '#facc15'],
            ]],
            'demo-hama-safe-dose' => ['صيدلية الجرعة الآمنة', 'Safe Dose Pharmacy', ['pharmacies'], 25, null, [
                'barudiyah' => ['فرع البارودية (٢٤ ساعة)', 'Al Barudiyah branch (24 h)', 'barudiyah', 50, -40, 'always'],
                'ain-al-lawza' => ['فرع عين اللوزة', 'Ain Al-Lawza branch', 'ain-al-lawza', 30, 60, 'pharmacy'],
            ], [
                'paracetamol' => ['باراسيتامول ٥٠٠ ملغ', 'Paracetamol 500 mg', '60', ['demo-medicine'], [], '#16a34a'],
                'ibuprofen' => ['إيبوبروفين ٤٠٠ ملغ', 'Ibuprofen 400 mg', '85', ['demo-medicine'], [], '#0d9488'],
                'vitamin-c' => ['فيتامين سي ١٠٠٠ ملغ', 'Vitamin C 1000 mg', '220', ['demo-medicine', 'demo-personal-care'], [], '#f97316'],
                'sanitizer' => ['معقم يدين', 'Hand sanitizer', '90', ['demo-personal-care'], [], '#14b8a6'],
                'baby-wipes' => ['مناديل أطفال', 'Baby wipes', '120', ['demo-personal-care'], [], '#a855f7'],
                'thermometer' => ['ميزان حرارة رقمي', 'Digital thermometer', '450', ['demo-health'], [], '#64748b'],
                'masks' => ['كمامات (علبة)', 'Face masks (box)', '150', ['demo-health'], [], '#38bdf8'],
            ]],
            'demo-hama-corner-pizza' => ['بيتزا الزاوية', 'Corner Pizza', ['restaurants'], 26, '200', [
                'tishrine' => ['فرع تشرين', 'Tishrine branch', 'tishrine', 40, 40, 'restaurant'],
            ], [
                'margherita' => ['بيتزا مارغريتا', 'Margherita pizza', '450', ['demo-pizza'], ['pizza-size'], '#dc2626'],
                'pepperoni' => ['بيتزا بيبروني', 'Pepperoni pizza', '600', ['demo-pizza'], ['pizza-size'], '#b91c1c'],
                'vegetable' => ['بيتزا خضار', 'Vegetable pizza', '500', ['demo-pizza'], ['pizza-size'], '#16a34a'],
                'garlic-bread' => ['خبز بالثوم', 'Garlic bread', '150', ['demo-bakery'], [], '#ca8a04'],
                'cola' => ['مشروب غازي', 'Soft drink', '75', ['demo-beverages'], [], '#1e3a8a'],
            ]],
            'demo-hama-norias-juice' => ['عصائر النواعير', 'Norias Juice Bar', ['restaurants'], 27, null, [
                'bab-qibli' => ['فرع باب القبلي', 'Bab Qibli branch', 'bab-qibli', -20, 30, 'juice'],
            ], [
                'orange' => ['عصير برتقال', 'Orange juice', '150', ['demo-juices'], ['cup'], '#f97316'],
                'lemon-mint' => ['ليمون ونعنع', 'Lemon and mint', '120', ['demo-juices'], ['cup'], '#84cc16'],
                'avocado' => ['كوكتيل أفوكادو', 'Avocado cocktail', '350', ['demo-juices'], [], '#4d7c0f'],
                'fruit-salad' => ['سلطة فواكه', 'Fruit salad', '250', ['demo-food'], [], '#db2777'],
            ]],
            'demo-hama-tech-point' => ['نقطة التقنية', 'Tech Point', ['shops'], 28, null, [
                'aidin' => ['فرع العائدين', 'Al Aidin branch', 'aidin', 30, -50, 'shop'],
            ], [
                'charger' => ['شاحن جوال سريع', 'Fast phone charger', '450', ['demo-accessories'], [], '#475569'],
                'usb-c' => ['كابل USB-C', 'USB-C cable', '250', ['demo-accessories'], [], '#334155'],
                'earphones' => ['سماعات أذن', 'Earphones', '700', ['demo-accessories', 'demo-electronics'], [], '#1f2937'],
                'power-bank' => ['بطارية محمولة ١٠٠٠٠', 'Power bank 10000 mAh', '1500', ['demo-electronics'], [], '#0f172a'],
            ]],
        ];
    }

    /** Fictional people; addresses spread from the old centre to the outer villages. */
    protected function customers(): array
    {
        return [
            1 => ['أحمد', 'الحموي', 'ar', [
                ['البيت', 'Home', 'zanbaqi', 150, 90, 'قرب الجامع النوري', 'Near Al-Nouri Mosque'],
                ['العمل', 'Work', 'al-madinah', -60, 40, 'سوق الطويل', 'Al-Tawil Souq'],
            ]],
            2 => ['سارة', 'العلي', 'ar', [['البيت', 'Home', 'gharb-al-mashtal', 80, -60, 'شارع المشتل', 'Al-Mashtal street']]],
            3 => ['محمد', 'الخطيب', 'ar', [['البيت', 'Home', 'tishrine', -70, 50, 'حي تشرين', 'Tishrine']]],
            4 => ['ريم', 'الأحمد', 'en', [['البيت', 'Home', 'shahba', 60, 80, 'الشهباء', 'Ash Shahba']]],
            5 => ['خالد', 'الشامي', 'ar', [['البيت', 'Home', 'souq-al-ghanam', -50, -60, 'سوق الغنم', 'Souq Al-Ghanam']]],
            6 => ['نور', 'حداد', 'ar', [['البيت', 'Home', 'dahiyat-abi-al-fidaa', 40, 30, 'ضاحية أبي الفداء', 'Dahiyat Abi Al-Fidaa']]],
            7 => ['ياسر', 'الحسن', 'ar', [['البيت', 'Home', 'kazu', 30, 40, 'كازو', 'Kazu']]],
            8 => ['هبة', 'الموصلي', 'en', [['البيت', 'Home', 'andalus', -60, -40, 'الأندلس', 'Al-Andalus']]],
        ];
    }

    /** n => [name, centre, vehicle, plate, availability after seeding, metres north, metres east] */
    protected function drivers(): array
    {
        return [
            1 => ['عمر قصاب', 'zanbaqi', 'motorcycle', 'DEMO-HM-301', Driver::AVAILABILITY_AVAILABLE, 200, 150],
            2 => ['باسل العمر', 'shahba', 'motorcycle', 'DEMO-HM-302', Driver::AVAILABILITY_AVAILABLE, -100, 80],
            3 => ['مازن السيد', 'nasr', 'car', 'DEMO-HC-303', Driver::AVAILABILITY_AVAILABLE, 50, -90],
            4 => ['وسيم دياب', 'fayhaa', 'motorcycle', 'DEMO-HM-304', Driver::AVAILABILITY_OFFLINE, 30, 30],
        ];
    }

    protected function scenarios(): array
    {
        $grill = 'demo-hama-orontes-grill';
        $halawa = 'demo-hama-halawa-corner';
        $bakery = 'demo-hama-golden-sheaf';
        $market = 'demo-hama-fresh-basket';
        $pharmacy = 'demo-hama-safe-dose';
        $pizza = 'demo-hama-corner-pizza';
        $juice = 'demo-hama-norias-juice';
        $delivered = fn (int $driver) => [[5, 'accept'], [10, 'assign', $driver], [25, 'pickup'], [45, 'deliver']];

        // Times: [days, hour, minute] in the operations time zone (evenings,
        // when every branch is open), or minutes before "now" for the live
        // orders, which use the 24-hour branches only.
        return [
            // ---- Delivered history ----
            '0001' => [[-6, 19, 0], 1, $grill, 'mashtal', [['chicken-shawarma', 2, ['saj', 'garlic']], ['ayran', 2, []]], [], $delivered(1)],
            '0002' => [[-5, 18, 30], 4, $market, 'shahba', [['milk', 2, []], ['eggs', 1, []], ['rice', 1, []]], [], $delivered(2)],
            '0003' => [[-4, 19, 30], 2, $halawa, 'madinah', [['halawa', 1, ['kilo']]], [],
                [...$delivered(3), [90, 'refund', ['100', 'missing_item', 'Demo: one piece missing']], [100, 'rate', [5, 'حلاوة الجبن ممتازة']]]],
            '0004' => [[-3, 18, 0], 6, $pharmacy, 'ain-al-lawza', [['paracetamol', 2, []], ['vitamin-c', 1, []]], [], $delivered(3)],
            '0005' => [[-2, 20, 0], 3, $pizza, 'tishrine', [['margherita', 1, ['large']], ['cola', 2, []]], [], [...$delivered(2), [80, 'rate', [4, 'Good pizza']]]],
            '0006' => [[-2, 18, 0], 7, $bakery, 'hamidiyah', [['zaatar', 6, []], ['cheese-manakish', 4, []]], [], $delivered(1)],
            '0007' => [[-1, 19, 0], 5, $juice, 'bab-qibli', [['orange', 2, ['large']]], [], $delivered(4)],

            // ---- Cancelled ----
            '0008' => [[-1, 18, 0], 8, $grill, 'mashtal', [['kebab', 1, ['garlic']]], [], [[3, 'cancel_customer', 'Ordered by mistake']]],
            '0009' => [[-3, 19, 0], 1, $market, 'andalus', [['olive-oil', 1, []]], [],
                [[5, 'accept'], [15, 'cancel_admin', ['reason_code' => 'product_unavailable', 'note' => 'Demo: out of stock']]]],
            // Paid partly from the wallet (the refund above), cancelled after pickup: the wallet part goes back.
            '0010' => [[-2, 19, 30], 2, $grill, 'zanbaqi', [['mixed-grill', 1, []]], ['wallet' => '100'],
                [[5, 'accept'], [8, 'assign', 2], [20, 'pickup'], [35, 'cancel_admin', ['reason_code' => 'customer_refused', 'note' => 'Demo: refused at the door', 'driver_handling' => 'returned_to_branch', 'cash_collected_amount' => '0']]]],

            // ---- Live (24-hour branches): pending, accepted, assigned, picked up, cancellation request ----
            '0011' => [-8, 3, $grill, 'zanbaqi', [['chicken-shawarma', 1, ['tannour', 'fries']]], [], []],
            '0012' => [-12, 6, $pharmacy, 'barudiyah', [['sanitizer', 1, []], ['masks', 1, []]], [], [[3, 'accept']]],
            '0013' => [-20, 1, $grill, 'zanbaqi', [['shawarma-plate', 1, ['garlic']], ['ayran', 1, []]], [], [[3, 'accept'], [8, 'assign', 1]]],
            '0014' => [-30, 5, $pharmacy, 'barudiyah', [['thermometer', 1, []]], [], [[3, 'accept'], [6, 'assign', 3], [15, 'pickup']]],
            '0015' => [-25, 4, $grill, 'zanbaqi', [['hummus', 2, []]], [], [[3, 'accept'], [10, 'request_cancel', 'Can I still cancel?']]],
            // Scheduled for tomorrow lunch time.
            '0016' => [-60, 8, $grill, 'mashtal', [['beef-shawarma', 2, ['saj']]], ['scheduled_for' => [1, 13, 0]], []],
        ];
    }

    /** One PLUS member in Hama, through the real request → approval flow (when a plan exists). */
    protected function orders(): void
    {
        parent::orders();

        $plan = PlusPlan::query()->where('is_active', true)->orderBy('id')->first();
        $member = $this->customer(4);
        if (! $plan || PlusMembership::query()->where('user_id', $member->id)->exists()) {
            return;
        }
        $this->context->at($this->context->anchor()->subDays(1), function () use ($plan, $member) {
            $request = PlusRequest::create([
                'user_id' => $member->id, 'type' => PlusRequest::TYPE_NEW, 'requested_plan_id' => $plan->id,
                'source' => 'customer', 'customer_note' => 'Demo: Hama PLUS member',
            ]);
            app(PlusService::class)->approveNew($request, $this->context->operator(), PlusMembership::POLICY_MONTHLY);
        });
    }
}
