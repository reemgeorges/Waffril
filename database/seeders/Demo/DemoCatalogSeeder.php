<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\BranchHourException;
use App\Models\BranchOpeningHour;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Category;
use App\Models\City;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\StoreCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * WAFFRLI demo — store categories, a product-category tree, six demo
 * stores with branches (weekly hours + exceptional closures), products with
 * exactly one generated image each, several categories per product, branch
 * prices/availability, option groups (sizes, paid extras) with
 * branch-specific option prices, and public offers.
 *
 * Prices are in the default currency. Branches that receive "live" demo
 * orders are open around the clock so the demo works at any hour; the
 * others show realistic hours.
 */
class DemoCatalogSeeder extends Seeder
{
    public const STORE_CATEGORIES = [
        'restaurants' => ['مطاعم', 'Restaurants'],
        'supermarkets' => ['سوبرماركت', 'Supermarkets'],
        'pharmacies' => ['صيدليات', 'Pharmacies'],
        'bakeries' => ['مخابز', 'Bakeries'],
        'shops' => ['متاجر', 'Shops'],
    ];

    /** slug => [ar, en, parent slug] */
    public const CATEGORIES = [
        'demo-food' => ['طعام', 'Food', null],
        'demo-grills' => ['مشاوي', 'Grills', 'demo-food'],
        'demo-pizza' => ['بيتزا', 'Pizza', 'demo-food'],
        'demo-sandwiches' => ['سندويش', 'Sandwiches', 'demo-food'],
        'demo-bakery' => ['مخبوزات', 'Bakery', 'demo-food'],
        'demo-groceries' => ['بقالة', 'Groceries', null],
        'demo-dairy' => ['ألبان وأجبان', 'Dairy', 'demo-groceries'],
        'demo-beverages' => ['مشروبات', 'Beverages', 'demo-groceries'],
        'demo-snacks' => ['تسالي', 'Snacks', 'demo-groceries'],
        'demo-health' => ['صحة', 'Health', null],
        'demo-medicine' => ['أدوية', 'Medicine', 'demo-health'],
        'demo-personal-care' => ['عناية شخصية', 'Personal care', 'demo-health'],
        'demo-electronics' => ['إلكترونيات', 'Electronics', null],
        'demo-accessories' => ['إكسسوارات', 'Accessories', 'demo-electronics'],
    ];

    /** Option presets: group key => [ar, en, kind, min, max, options: key => [ar, en, default delta]] */
    public const OPTION_PRESETS = [
        'size' => ['الحجم', 'Size', 'size', 1, 1, [
            'small' => ['صغير', 'Small', '0'], 'medium' => ['وسط', 'Medium', '0.75'], 'large' => ['كبير', 'Large', '1.50'],
        ]],
        'extras' => ['إضافات', 'Extras', 'addon', 0, 3, [
            'garlic-sauce' => ['صلصة ثوم', 'Garlic sauce', '0.25'], 'extra-cheese' => ['جبنة إضافية', 'Extra cheese', '0.50'], 'fries' => ['بطاطا مقلية', 'Fries', '1.00'],
        ]],
        'bread' => ['نوع الخبز', 'Bread', 'choice', 1, 1, [
            'saj' => ['خبز صاج', 'Saj bread', '0'], 'tannour' => ['خبز تنور', 'Tannour bread', '0.10'],
        ]],
    ];

    /**
     * slug => [ar, en, store categories, phone n, branches, products]
     * branch key => [ar, en, city, lat, lng, hours preset]
     * product key => [ar, en, price, categories, option presets, colour]
     */
    public static function stores(): array
    {
        return [
            'demo-shami-grill' => ['مشاوي الشامي', 'Shami Grill', ['restaurants'], 11, [
                'mazzeh' => ['فرع المزة', 'Mazzeh branch', 'Damascus', 33.5050, 36.2450, 'always'],
                'old-city' => ['فرع المدينة القديمة', 'Old City branch', 'Damascus', 33.5110, 36.3050, 'always'],
                'aleppo' => ['فرع حلب', 'Aleppo branch', 'Aleppo', 36.2050, 37.1400, 'always'],
            ], [
                'shawarma-wrap' => ['شاورما دجاج', 'Chicken shawarma wrap', '2.50', ['demo-sandwiches', 'demo-grills'], ['bread', 'extras'], '#c2410c'],
                'mixed-grill' => ['مشاوي مشكلة', 'Mixed grill platter', '8.00', ['demo-grills', 'demo-food'], ['size'], '#9a3412'],
                'kebab-plate' => ['صحن كباب', 'Kebab plate', '6.50', ['demo-grills', 'demo-food'], ['extras'], '#b45309'],
                'hummus' => ['حمص', 'Hummus', '1.50', ['demo-food'], [], '#a16207'],
                'ayran' => ['عيران', 'Ayran', '0.60', ['demo-beverages', 'demo-dairy'], [], '#0369a1'],
            ]],
            'demo-pizza-roma' => ['بيتزا روما', 'Pizza Roma', ['restaurants'], 12, [
                'mazzeh-west' => ['فرع المزة الغربية', 'West Mazzeh branch', 'Damascus', 33.5000, 36.2350, 'always'],
                'aleppo-center' => ['فرع حلب الوسطى', 'Aleppo center branch', 'Aleppo', 36.2000, 37.1250, 'evening'],
            ], [
                'margherita' => ['بيتزا مارغريتا', 'Margherita pizza', '5.00', ['demo-pizza', 'demo-food'], ['size', 'extras'], '#dc2626'],
                'pepperoni' => ['بيتزا بيبروني', 'Pepperoni pizza', '6.00', ['demo-pizza', 'demo-food'], ['size'], '#b91c1c'],
                'garlic-bread' => ['خبز بالثوم', 'Garlic bread', '1.80', ['demo-bakery', 'demo-food'], [], '#ca8a04'],
                'cola' => ['مشروب غازي', 'Cola', '0.80', ['demo-beverages'], [], '#1e3a8a'],
            ]],
            'demo-fresh-market' => ['السوق الطازج', 'Fresh Market', ['supermarkets'], 13, [
                'center' => ['فرع الشعلان', 'Shaalan branch', 'Damascus', 33.5150, 36.2950, 'always'],
                'north' => ['فرع الصالحية', 'Salhiyeh branch', 'Damascus', 33.5450, 36.2900, 'always'],
            ], [
                'milk' => ['حليب ١ لتر', 'Milk 1 L', '1.10', ['demo-dairy', 'demo-beverages'], [], '#2563eb'],
                'labneh' => ['لبنة', 'Labneh 500 g', '2.20', ['demo-dairy', 'demo-groceries'], [], '#0891b2'],
                'water-6' => ['مياه معدنية ٦ عبوات', 'Mineral water 6-pack', '1.50', ['demo-beverages', 'demo-groceries'], [], '#0ea5e9'],
                'olive-oil' => ['زيت زيتون ١ لتر', 'Olive oil 1 L', '7.50', ['demo-groceries'], [], '#4d7c0f'],
                'chips' => ['شيبس', 'Potato chips', '0.70', ['demo-snacks', 'demo-groceries'], [], '#eab308'],
            ]],
            'demo-shifa-pharmacy' => ['صيدلية الشفاء', 'Al-Shifa Pharmacy', ['pharmacies'], 14, [
                'mazzeh' => ['فرع المزة', 'Mazzeh branch', 'Damascus', 33.5080, 36.2550, 'always'],
                'homs' => ['فرع حمص', 'Homs branch', 'Homs', 34.7300, 36.7100, 'daytime'],
            ], [
                'paracetamol' => ['باراسيتامول', 'Paracetamol 500 mg', '1.20', ['demo-medicine', 'demo-health'], [], '#16a34a'],
                'vitamin-c' => ['فيتامين سي', 'Vitamin C 1000 mg', '4.00', ['demo-medicine', 'demo-personal-care'], [], '#f97316'],
                'hand-sanitizer' => ['معقم يدين', 'Hand sanitizer', '1.50', ['demo-personal-care', 'demo-health'], [], '#14b8a6'],
                'baby-wipes' => ['مناديل أطفال', 'Baby wipes', '2.00', ['demo-personal-care'], [], '#a855f7'],
            ]],
            'demo-golden-bakery' => ['المخبز الذهبي', 'Golden Bakery', ['bakeries', 'restaurants'], 15, [
                'salhiyeh' => ['فرع الصالحية', 'Salhiyeh branch', 'Damascus', 33.5400, 36.2850, 'split'],
            ], [
                'manakish' => ['مناقيش زعتر', 'Zaatar manakish', '0.90', ['demo-bakery', 'demo-food'], ['extras'], '#65a30d'],
                'croissant' => ['كرواسون بالجبنة', 'Cheese croissant', '1.20', ['demo-bakery'], [], '#d97706'],
                'maamoul' => ['معمول بالتمر', 'Date maamoul box', '3.50', ['demo-bakery', 'demo-snacks'], [], '#78350f'],
            ]],
            'demo-tech-corner' => ['ركن التقنية', 'Tech Corner', ['shops'], 16, [
                'center' => ['فرع الحمرا', 'Hamra branch', 'Damascus', 33.5120, 36.2900, 'shop'],
            ], [
                'usb-c-cable' => ['كابل USB-C', 'USB-C cable', '4.50', ['demo-accessories'], [], '#475569'],
                'power-bank' => ['بطارية محمولة', 'Power bank 10000 mAh', '18.00', ['demo-accessories', 'demo-electronics'], [], '#334155'],
                'earbuds' => ['سماعات لاسلكية', 'Wireless earbuds', '25.00', ['demo-accessories', 'demo-electronics'], [], '#1f2937'],
            ]],
        ];
    }

    /** Weekly hours presets: list of [day(s), opens, closes]. */
    protected const HOURS = [
        'always' => [[[0, 1, 2, 3, 4, 5, 6], '00:00:00', '23:59:59']],
        'evening' => [[[0, 1, 2, 3, 4, 5, 6], '11:00:00', '23:59:59']],
        'daytime' => [[[0, 1, 2, 3, 4, 6], '08:00:00', '22:00:00'], [[5], '14:00:00', '22:00:00']],
        'split' => [[[0, 1, 2, 3, 4, 5, 6], '06:00:00', '14:00:00'], [[0, 1, 2, 3, 4, 5, 6], '16:00:00', '23:00:00']],
        'shop' => [[[0, 1, 2, 3, 4, 5, 6], '10:00:00', '22:00:00']],
    ];

    public function run(): void
    {
        $context = app(DemoContext::class);
        $admin = $context->operator();

        $context->at($context->anchor()->subDays(30), function () use ($context, $admin) {
            $storeCategories = $this->storeCategories();
            $categories = $this->categories();

            foreach (self::stores() as $slug => [$ar, $en, $storeCats, $phone, $branches, $products]) {
                $store = Store::withTrashed()->where('slug', $slug)->first();
                if ($store) {
                    continue; // created by an earlier run: never overwritten
                }

                $store = Store::create([
                    'name' => ['ar' => $ar, 'en' => $en],
                    'slug' => $slug,
                    'description' => ['ar' => "متجر تجريبي: {$ar}", 'en' => "Demo store: {$en}"],
                    'contact_phone' => DemoContext::phone($phone),
                    'contact_whatsapp' => DemoContext::phone($phone),
                    'is_active' => true,
                ]);
                $store->categories()->sync(array_map(fn ($key) => $storeCategories[$key], $storeCats));

                $branchModels = [];
                foreach ($branches as $key => [$bAr, $bEn, $cityEn, $lat, $lng, $hours]) {
                    $branchModels[$key] = $this->branch($store, $key, $bAr, $bEn, $cityEn, $lat, $lng, $hours);
                }

                $index = 0;
                foreach ($products as $key => [$pAr, $pEn, $price, $productCats, $presets, $colour]) {
                    $this->product($store, $branchModels, $key, $pAr, $pEn, $price, array_map(fn ($c) => $categories[$c], $productCats), $presets, $colour, $index++);
                }
            }

            $this->exceptions($context, $admin->id);
            $this->offers($context, $admin->id);
        });
    }

    protected function storeCategories(): array
    {
        $ids = [];
        $sort = 0;
        foreach (self::STORE_CATEGORIES as $key => [$ar, $en]) {
            $slug = 'demo-'.$key;
            $ids[$key] = (StoreCategory::withTrashed()->where('slug', $slug)->first()
                ?? StoreCategory::create(['name' => ['ar' => $ar, 'en' => $en], 'slug' => $slug, 'sort_order' => ++$sort, 'is_active' => true]))->id;
        }

        return $ids;
    }

    protected function categories(): array
    {
        $ids = [];
        $sort = 0;
        foreach (self::CATEGORIES as $slug => [$ar, $en, $parent]) {
            $ids[$slug] = (Category::withTrashed()->where('slug', $slug)->first()
                ?? Category::create(['name' => ['ar' => $ar, 'en' => $en], 'slug' => $slug, 'parent_id' => $parent ? $ids[$parent] : null, 'sort_order' => ++$sort, 'is_active' => true]))->id;
        }

        return $ids;
    }

    protected function branch(Store $store, string $key, string $ar, string $en, string $cityEn, float $lat, float $lng, string $hours): Branch
    {
        $city = City::query()->where('name->en', $cityEn)->firstOrFail();
        $branch = Branch::create([
            'store_id' => $store->id, 'city_id' => $city->id,
            'name' => ['ar' => $ar, 'en' => $en],
            'address' => ['ar' => "{$ar}، {$city->getTranslation('name', 'ar')}", 'en' => "{$en}, {$cityEn}"],
            'lat' => $lat, 'lng' => $lng,
            'phone' => $store->contact_phone,
            'min_order_amount' => $store->slug === 'demo-fresh-market' ? '3.00' : null,
            'is_active' => true, 'is_accepting_orders' => true,
        ]);

        foreach (self::HOURS[$hours] as [$days, $opens, $closes]) {
            foreach ($days as $day) {
                BranchOpeningHour::create(['branch_id' => $branch->id, 'day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes]);
            }
        }

        return $branch;
    }

    /** @param  array<string, Branch>  $branches */
    protected function product(Store $store, array $branches, string $key, string $ar, string $en, string $price, array $categoryIds, array $presets, string $colour, int $index): void
    {
        $product = Product::create([
            'store_id' => $store->id,
            'name' => ['ar' => $ar, 'en' => $en],
            'description' => ['ar' => "{$ar} — منتج تجريبي.", 'en' => "{$en} — demo product."],
            'image' => $this->image($store->slug, $key, $en, $colour),
            'is_active' => true,
        ]);
        $product->categories()->sync($categoryIds);

        $options = [];
        $groupSort = 0;
        foreach ($presets as $presetKey) {
            [$gAr, $gEn, $kind, $min, $max, $presetOptions] = self::OPTION_PRESETS[$presetKey];
            $group = ProductOptionGroup::create([
                'product_id' => $product->id, 'name' => ['ar' => $gAr, 'en' => $gEn],
                'kind' => $kind, 'min_select' => $min, 'max_select' => $max, 'sort_order' => ++$groupSort, 'is_active' => true,
            ]);
            $optionSort = 0;
            foreach ($presetOptions as $optionKey => [$oAr, $oEn, $delta]) {
                $options[$optionKey] = [ProductOption::create([
                    'option_group_id' => $group->id, 'product_id' => $product->id,
                    'name' => ['ar' => $oAr, 'en' => $oEn], 'max_quantity' => $kind === 'addon' ? 2 : 1,
                    'sort_order' => ++$optionSort, 'is_active' => true,
                ]), $delta];
            }
        }

        $branchIndex = 0;
        foreach ($branches as $branchKey => $branch) {
            // Branch-specific pricing: +0 %, +5 %, -4 % by branch; one product
            // is unavailable at a non-primary branch to show availability.
            $factor = ['1', '1.05', '0.96'][$branchIndex % 3];
            $branchProduct = BranchProduct::create([
                'branch_id' => $branch->id, 'product_id' => $product->id, 'store_id' => $store->id,
                'price' => bcmul($price, $factor, 2),
                'is_available' => ! ($branchIndex > 0 && $index === 3),
            ]);

            foreach ($options as $optionKey => [$option, $delta]) {
                BranchProductOption::create([
                    'branch_product_id' => $branchProduct->id, 'product_id' => $product->id, 'product_option_id' => $option->id,
                    'price_delta' => bcadd($delta, $branchIndex > 0 && $delta !== '0' ? '0.10' : '0', 2),
                    'is_available' => ! ($branchIndex === 1 && $optionKey === 'fries'),
                ]);
            }
            $branchIndex++;
        }
    }

    /** One simple generated SVG per product (no external images), stored on the public disk. */
    protected function image(string $storeSlug, string $key, string $label, string $colour): string
    {
        $path = "demo/products/{$storeSlug}-{$key}.svg";
        if (! Storage::disk('public')->exists($path)) {
            $text = htmlspecialchars($label, ENT_XML1);
            $initial = htmlspecialchars(mb_strtoupper(mb_substr($label, 0, 1)), ENT_XML1);
            Storage::disk('public')->put($path, <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="480" height="360" viewBox="0 0 480 360">
  <rect width="480" height="360" fill="{$colour}"/>
  <circle cx="240" cy="150" r="80" fill="#ffffff" fill-opacity="0.18"/>
  <text x="240" y="178" font-family="Arial, sans-serif" font-size="84" font-weight="700" fill="#ffffff" text-anchor="middle">{$initial}</text>
  <text x="240" y="290" font-family="Arial, sans-serif" font-size="26" fill="#ffffff" text-anchor="middle">{$text}</text>
  <text x="240" y="330" font-family="Arial, sans-serif" font-size="16" fill="#ffffff" fill-opacity="0.75" text-anchor="middle">WAFFRLI demo</text>
</svg>
SVG);
        }

        return $path;
    }

    protected function exceptions(DemoContext $context, int $adminId): void
    {
        $rows = [
            ['demo-golden-bakery', 'salhiyeh', BranchHourException::KIND_CLOSED, 2, 26, ['ar' => 'إغلاق للعيد', 'en' => 'Closed for the holiday']],
            ['demo-shami-grill', 'old-city', BranchHourException::KIND_CLOSED, -20, 6, ['ar' => 'صيانة', 'en' => 'Maintenance']],
            ['demo-pizza-roma', 'aleppo-center', BranchHourException::KIND_OPEN, 3, 4, ['ar' => 'دوام صباحي خاص', 'en' => 'Special morning opening']],
            ['demo-tech-corner', 'center', BranchHourException::KIND_CLOSED, 5, 30, ['ar' => 'جرد سنوي', 'en' => 'Annual stock-take']],
        ];

        foreach ($rows as [$storeSlug, $branchKey, $kind, $dayOffset, $hours, $reason]) {
            $branch = $this->findBranch($storeSlug, $branchKey);
            $starts = $context->anchor()->addDays($dayOffset)->startOfDay()->addHours(8);
            BranchHourException::query()->where('branch_id', $branch->id)->where('reason->en', $reason['en'])->exists()
                || BranchHourException::create([
                    'branch_id' => $branch->id, 'kind' => $kind, 'starts_at' => $starts, 'ends_at' => $starts->copy()->addHours($hours),
                    'reason' => $reason, 'created_by_admin_id' => $adminId,
                ]);
        }
    }

    protected function offers(DemoContext $context, int $adminId): void
    {
        $rows = [
            // [store, branch, product, ar, en, type, value, starts (days), ends (days)]
            ['demo-shami-grill', null, 'mixed-grill', 'خصم على المشاوي', 'Grill week', 'percent', '15', -20, 10],
            ['demo-fresh-market', 'center', 'olive-oil', 'زيت الموسم', 'Seasonal olive oil', 'percent', '10', -15, 15],
            ['demo-pizza-roma', null, 'garlic-bread', 'خبز الثوم بسعر أقل', 'Garlic bread deal', 'fixed', '0.30', -10, 20],
            ['demo-shifa-pharmacy', null, 'vitamin-c', 'عرض الشتاء', 'Winter vitamins', 'percent', '20', -40, -5],
            ['demo-golden-bakery', null, 'maamoul', 'معمول العيد', 'Holiday maamoul', 'percent', '12', 3, 20],
        ];

        foreach ($rows as [$storeSlug, $branchKey, $productKey, $ar, $en, $type, $value, $from, $to]) {
            $store = Store::query()->where('slug', $storeSlug)->firstOrFail();
            if (Offer::withTrashed()->where('store_id', $store->id)->where('name->en', $en)->exists()) {
                continue;
            }
            Offer::create([
                'store_id' => $store->id,
                'branch_id' => $branchKey ? $this->findBranch($storeSlug, $branchKey)->id : null,
                'product_id' => self::findProduct($storeSlug, $productKey)->id,
                'name' => ['ar' => $ar, 'en' => $en],
                'discount_type' => $type, 'discount_value' => $value,
                'starts_at' => $context->anchor()->addDays($from), 'ends_at' => $context->anchor()->addDays($to),
                'is_active' => true, 'created_by_admin_id' => $adminId,
            ]);
        }
    }

    public function findBranch(string $storeSlug, string $branchKey): Branch
    {
        return self::branchFor($storeSlug, $branchKey);
    }

    public static function branchFor(string $storeSlug, string $branchKey): Branch
    {
        $en = self::stores()[$storeSlug][4][$branchKey][1];

        return Branch::query()->whereHas('store', fn ($query) => $query->where('slug', $storeSlug))->where('name->en', $en)->firstOrFail();
    }

    public static function findProduct(string $storeSlug, string $productKey): Product
    {
        $en = self::stores()[$storeSlug][5][$productKey][1];

        return Product::query()->whereHas('store', fn ($query) => $query->where('slug', $storeSlug))->where('name->en', $en)->firstOrFail();
    }
}
