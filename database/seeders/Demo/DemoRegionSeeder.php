<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\BranchOpeningHour;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Category;
use App\Models\City;
use App\Models\Currency;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Currency\OperationalMoney;
use App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
use App\Services\Ratings\OrderDriverRatingService;
use App\Support\Money\Denomination;
use App\Support\Money\Money;
use App\Support\Settings\PlatformSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\Demo\Geo\NearestCentrePartition;
use Database\Seeders\WaffrliDemoSeeder;
use Database\Seeders\WaffrliEssentialSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * WAFFRLI demo data (2026-11-16) — one demonstration CITY for the customer
 * and driver apps: the city, its complete delivery-area coverage, fee
 * rules, stores with branches, products and Syrian-pound prices, opening
 * hours, demo customers with addresses, drivers and representative orders.
 *
 * Geography comes from a reviewed data file (data/<city>.json) built from
 * OpenStreetMap: a VERIFIED city boundary (one "whole service area" with
 * the lowest priority, so no valid address is ever rejected) and named
 * centres that split it into APPROXIMATE "nearest centre" areas (see
 * Geo\NearestCentrePartition). Each area records its boundary quality and
 * source; nothing is presented as an official neighbourhood boundary.
 *
 * Money: prices are written in NEW Syrian pounds (what people see) and
 * stored through OperationalMoney::toBase(), the same exact conversion the
 * admin panel uses (base currency amount when the base is USD; old-pound
 * units when the base is SYP). A price with no exact base amount is moved
 * to the nearest exactly storable shop-like amount (steps of 5 SYP below
 * 200, of 10/25/50 above) and reported — never rounded silently.
 *
 * Safety:
 *  - local / development / demo / testing environments only;
 *  - reference data (city, areas, rules, catalog, people) is additive and
 *    matched by natural keys: existing rows are never modified or deleted;
 *  - demo TRANSACTIONS (orders, payments, refunds, ratings) are added only
 *    when the database holds no real orders or wallet movements
 *    (DemoContext::transactionsAllowed()); otherwise they are skipped.
 */
abstract class DemoRegionSeeder extends Seeder
{
    /** The whole-city area has the lowest priority: named areas win where they overlap. */
    public const CITY_AREA_SORT = 1000;

    public const AREA_SORT_START = 10;

    /** Opening hours presets: [days (0 = Sunday … 6 = Saturday), opens, closes]. */
    public const HOURS = [
        'always' => [[[0, 1, 2, 3, 4, 5, 6], '00:00:00', '23:59:59']],
        'restaurant' => [[[0, 1, 2, 3, 4, 5, 6], '11:00:00', '23:59:59']],
        'bakery' => [[[0, 1, 2, 3, 4, 5, 6], '06:00:00', '14:00:00'], [[0, 1, 2, 3, 4, 5, 6], '17:00:00', '22:00:00']],
        'market' => [[[0, 1, 2, 3, 4, 5, 6], '08:00:00', '23:00:00']],
        'pharmacy' => [[[0, 1, 2, 3, 4, 6], '08:00:00', '23:00:00'], [[5], '10:00:00', '23:00:00']],
        'shop' => [[[0, 1, 2, 3, 4, 6], '10:00:00', '22:00:00'], [[5], '14:00:00', '22:00:00']],
        'juice' => [[[0, 1, 2, 3, 4, 5, 6], '10:00:00', '23:59:59']],
    ];

    /** Option presets in NEW Syrian pounds: key => [ar, en, kind, min, max, [option key => [ar, en, delta]]]. */
    public const OPTION_PRESETS = [
        'bread' => ['نوع الخبز', 'Bread', 'choice', 1, 1, ['saj' => ['خبز صاج', 'Saj bread', '0'], 'tannour' => ['خبز تنور', 'Tannour bread', '15']]],
        'sauce' => ['إضافات', 'Extras', 'addon', 0, 3, ['garlic' => ['ثومية إضافية', 'Extra garlic sauce', '20'], 'cheese' => ['جبنة إضافية', 'Extra cheese', '40'], 'fries' => ['بطاطا مقلية', 'Fries', '80']]],
        'pizza-size' => ['الحجم', 'Size', 'size', 1, 1, ['small' => ['صغير', 'Small', '0'], 'medium' => ['وسط', 'Medium', '150'], 'large' => ['كبير', 'Large', '300']]],
        'box' => ['الوزن', 'Weight', 'size', 1, 1, ['half' => ['نصف كيلو', 'Half kilo', '0'], 'kilo' => ['كيلو', 'One kilo', '500']]],
        'cup' => ['الحجم', 'Size', 'size', 1, 1, ['regular' => ['عادي', 'Regular', '0'], 'large' => ['كبير', 'Large', '60']]],
    ];

    /** Product categories added for the regional catalogs (slug => [ar, en, parent]). */
    public const EXTRA_CATEGORIES = [
        'demo-sweets' => ['حلويات', 'Sweets', 'demo-food'],
        'demo-juices' => ['عصائر', 'Juices', 'demo-beverages'],
    ];

    protected DemoContext $context;

    /** @var array<string, mixed> */
    protected array $region;

    /** @var list<string> SYP prices moved to the nearest exactly storable amount */
    public array $adjustedPrices = [];

    /** @var array<string, array<string, mixed>> */
    private static array $dataCache = [];

    abstract protected function dataFile(): string;

    /** Prefix of every demo order number of this city, e.g. "DEMO-HAMA-". */
    abstract protected function orderPrefix(): string;

    /** slug => [ar, en, [store categories], phone n, min order (new SYP)|null, branches, products] */
    abstract protected function stores(): array;

    /** n => [first, last, language, [[label ar, label en, centre key, metres north, metres east, line ar, line en]…]] */
    abstract protected function customers(): array;

    /** n => [name, centre key, vehicle, plate, availability, metres north, metres east] */
    abstract protected function drivers(): array;

    /** City-wide fee bands in NEW SYP: [[from km, to km, base, per km]…] */
    abstract protected function feeBands(): array;

    /** Area-specific bands: centre key => bands (most specific scope wins). */
    protected function areaFeeBands(): array
    {
        return [];
    }

    /** number => [when, customer n, store slug, branch key, [[product key, qty, [option keys]]], options, steps] */
    abstract protected function scenarios(): array;

    /** @return array<string, mixed> */
    public static function data(string $file): array
    {
        return self::$dataCache[$file] ??= json_decode((string) file_get_contents(__DIR__.'/data/'.$file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function run(): void
    {
        DemoContext::assertSafeEnvironment();
        if (! app()->bound(DemoContext::class)) {
            // Standalone run: the essential catalogue (roles, settings,
            // payment methods) is idempotent and never overwrites.
            $this->call(WaffrliEssentialSeeder::class);
        }

        WaffrliDemoSeeder::inDemoEnvironment(function (DemoContext $context) {
            $this->context = $context;
            $this->region = static::data($this->dataFile());
            // Regional demo data is priced and ordered in Syrian pounds,
            // whatever the operational currency of this installation is
            // (the test suite pins USD). Restored afterwards.
            $savedCurrency = [
                'waffrli.currency.operational_default' => config('waffrli.currency.operational_default'),
                'waffrli.currency.customer_currencies' => config('waffrli.currency.customer_currencies'),
            ];
            config([
                'waffrli.currency.operational_default' => 'SYP',
                'waffrli.currency.customer_currencies' => array_values(array_unique(array_merge((array) config('waffrli.currency.customer_currencies'), ['SYP']))),
            ]);
            OperationalMoney::flush();

            try {
                $this->seedRegion($context);
            } finally {
                config($savedCurrency);
                OperationalMoney::flush();
            }
        });
    }

    protected function seedRegion(DemoContext $context): void
    {
        $this->assertSypIsPriced();

        $context->at($context->anchor()->subDays(45), function () {
            $city = $this->city();
            $this->areas($city);
            $this->feeRules($city);
            $this->catalog($city);
            $this->people($city);
        });

        if (DemoContext::transactionsAllowed()) {
            $this->orders();
            $this->placeDrivers();
        } else {
            $this->command?->warn("Demo orders for {$this->region['city']['en']} were NOT added: this database holds real orders or wallet movements. Reference data (city, areas, stores, people) was added.");
        }

        if ($this->adjustedPrices !== []) {
            $this->command?->info('Demo SYP prices moved to the nearest exactly storable amount: '.implode('; ', $this->adjustedPrices));
        }
    }

    /* ------------------------------------------------------------ geography */

    public function city(): City
    {
        $data = $this->region['city'];
        $city = City::query()->where('name->en', $data['en'])->orWhere('name->ar', $data['ar'])->first();

        return $city ?? City::create([
            'name' => ['ar' => $data['ar'], 'en' => $data['en']],
            'center_lat' => $data['lat'], 'center_lng' => $data['lng'], 'radius_km' => $data['radius_km'],
            'is_active' => true,
        ]);
    }

    /** The approximate named areas, keyed by centre key (computed, deterministic). */
    public function namedAreaRings(): array
    {
        $centres = array_map(fn ($c) => [(float) $c['lat'], (float) $c['lng']], $this->region['centres']);

        return NearestCentrePartition::cells($this->region['boundary'], $centres);
    }

    protected function areas(City $city): void
    {
        $source = $this->region['source'];
        $cityName = $this->region['city'];
        // The whole service area: verified when it is the OSM city boundary
        // (Hama); an approximate service radius where OSM has none (Homs).
        $this->area($city, [
            'ar' => "{$cityName['ar']} — كامل منطقة الخدمة", 'en' => "{$cityName['en']} — whole service area",
        ], $this->region['boundary'], $this->region['boundary_quality'] ?? 'verified', "OpenStreetMap {$source['city_boundary']}", self::CITY_AREA_SORT);

        // Neighbourhoods with a real boundary in OSM win wherever they apply.
        $sort = 1;
        foreach ($this->region['verified_areas'] ?? [] as $verified) {
            $this->area($city, ['ar' => $verified['ar'], 'en' => $verified['en']], $verified['boundary'], 'verified',
                "OpenStreetMap {$verified['osm']} ({$verified['kind']})", $sort++);
        }

        $sort = self::AREA_SORT_START;
        foreach ($this->namedAreaRings() as $key => $ring) {
            $centre = $this->region['centres'][$key];
            $this->area($city, ['ar' => $centre['ar'], 'en' => $centre['en']], $ring, 'approximate',
                "Nearest-centre area around OpenStreetMap {$centre['osm']} ({$centre['kind']}), within {$source['city_boundary']}", $sort++);
        }
    }

    protected function area(City $city, array $name, array $ring, string $quality, string $source, int $sort): void
    {
        if (DeliveryArea::query()->where('city_id', $city->id)->where('name->en', $name['en'])->exists()) {
            return; // created earlier or by an admin: never overwritten
        }
        DeliveryArea::create([
            'city_id' => $city->id, 'name' => $name,
            'boundary' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            'boundary_quality' => $quality, 'boundary_source' => mb_substr($source, 0, 255),
            'is_active' => true, 'sort_order' => $sort,
        ]);
    }

    protected function feeRules(City $city): void
    {
        $adminId = $this->context->operator()->id;
        $service = app(DeliveryFeeRuleService::class);
        $persist = function (?int $areaId, array $bands) use ($city, $service, $adminId) {
            foreach ($bands as [$from, $to, $base, $perKm]) {
                $exists = DeliveryFeeRule::query()->where('city_id', $city->id)->where('delivery_area_id', $areaId)
                    ->where('distance_from_km', $from)->exists();
                $exists || $service->persist([
                    'city_id' => $city->id, 'delivery_area_id' => $areaId,
                    'distance_from_km' => $from, 'distance_to_km' => $to,
                    'base_fee' => $this->base($base, "fee {$from}-{$to} km base"),
                    'per_km_fee' => $this->base($perKm, "fee {$from}-{$to} km per km"),
                    'is_active' => true,
                ], null, $adminId);
            }
        };

        // City bands only when the city has none yet (an admin's rules win).
        if (! DeliveryFeeRule::query()->where('city_id', $city->id)->whereNull('delivery_area_id')->exists()) {
            $persist(null, $this->feeBands());
        }
        foreach ($this->areaFeeBands() as $centreKey => $bands) {
            $area = DeliveryArea::query()->where('city_id', $city->id)->where('name->en', $this->region['centres'][$centreKey]['en'])->first();
            if ($area) {
                $persist($area->id, $bands);
            }
        }
    }

    /** A centre position moved by metres (north, east). @return array{0: float, 1: float} [lat, lng] */
    public function position(string $centreKey, float $north = 0, float $east = 0): array
    {
        $centre = $this->region['centres'][$centreKey] ?? $this->region['verified_areas'][$centreKey] ?? throw new RuntimeException("Unknown demo centre {$centreKey}.");
        $lat = (float) $centre['lat'] + $north / 111320;
        $lng = (float) $centre['lng'] + $east / (111320 * cos(deg2rad((float) $centre['lat'])));
        if (! NearestCentrePartition::contains($this->region['boundary'], $lat, $lng)) {
            throw new RuntimeException("Demo position near {$centreKey} is outside the {$this->region['city']['en']} boundary.");
        }

        return [round($lat, 7), round($lng, 7)];
    }

    /* --------------------------------------------------------------- money */

    protected function assertSypIsPriced(): void
    {
        if (OperationalMoney::currency()?->code !== 'SYP' || OperationalMoney::rate() === null) {
            throw new RuntimeException('Regional demo data is priced in Syrian pounds: an active SYP currency with an exchange rate is required (seed WaffrliDemoSeeder or configure WAFFRLI_EXTRA_CURRENCIES).');
        }
    }

    /** A NEW-SYP amount → the exact stored base amount (see the class comment). */
    public function base(string $newSyp, string $what = ''): string
    {
        $value = BigDecimal::of($newSyp);
        if ($value->isZero()) {
            return '0';
        }
        // Nearest "shop-like" amounts first: steps of 5 below 200 SYP, of
        // 10/25/50 above (a 500 price becomes 490 or 510, never 505).
        $offsets = $value->isLessThan(200) ? [0, 5, -5, 10, -10, 15, -15, 20, -20]
            : ($value->isLessThan(1000) ? [0, -10, 10, -20, 20, -25, 25, -30, 30, 5, -5] : [0, -50, 50, -25, 25, -100, 100, -10, 10]);
        foreach ($offsets as $offset) {
            $candidate = $value->plus($offset);
            if ($candidate->isNegativeOrZero()) {
                continue;
            }
            $exact = OperationalMoney::toBase((string) $candidate)['exact'];
            if ($exact !== null) {
                if ($offset !== 0) {
                    $this->adjustedPrices[] = trim("{$what} {$newSyp} → {$candidate}");
                }

                return $exact;
            }
        }

        throw new RuntimeException("No exactly storable amount near {$newSyp} SYP ({$what}).");
    }

    /* ------------------------------------------------------------- catalog */

    protected function catalog(City $city): void
    {
        $storeCategories = $this->storeCategoryIds();
        $categories = $this->categoryIds();

        foreach ($this->stores() as $slug => [$ar, $en, $storeCats, $phone, $minOrder, $branches, $products]) {
            if (Store::withTrashed()->where('slug', $slug)->exists()) {
                continue; // created by an earlier run: never overwritten
            }

            $store = Store::create([
                'name' => ['ar' => $ar, 'en' => $en], 'slug' => $slug,
                'description' => [
                    'ar' => "متجر تجريبي وهمي في {$this->region['city']['ar']} لأغراض العرض والاختبار فقط: {$ar}",
                    'en' => "Fictional demo store in {$this->region['city']['en']}, for demonstration and testing only: {$en}",
                ],
                'contact_phone' => DemoContext::phone($phone), 'contact_whatsapp' => DemoContext::phone($phone),
                'is_active' => true,
            ]);
            $store->categories()->sync(array_map(fn ($key) => $storeCategories[$key], $storeCats));

            $branchModels = [];
            foreach ($branches as $key => [$bAr, $bEn, $centreKey, $north, $east, $hours]) {
                [$lat, $lng] = $this->position($centreKey, $north, $east);
                $centre = $this->region['centres'][$centreKey] ?? $this->region['verified_areas'][$centreKey];
                $branch = Branch::create([
                    'store_id' => $store->id, 'city_id' => $city->id,
                    'name' => ['ar' => $bAr, 'en' => $bEn],
                    'address' => ['ar' => "{$centre['ar']}، {$this->region['city']['ar']}", 'en' => "{$centre['en']}, {$this->region['city']['en']}"],
                    'lat' => $lat, 'lng' => $lng, 'phone' => $store->contact_phone,
                    'min_order_amount' => $minOrder !== null ? $this->base($minOrder, "{$slug} minimum order") : null,
                    'is_active' => true, 'is_accepting_orders' => true,
                ]);
                foreach (self::HOURS[$hours] as [$days, $opens, $closes]) {
                    foreach ($days as $day) {
                        BranchOpeningHour::create(['branch_id' => $branch->id, 'day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes]);
                    }
                }
                $branchModels[$key] = $branch;
            }

            $index = 0;
            foreach ($products as $key => [$pAr, $pEn, $price, $productCats, $presets, $colour]) {
                $this->product($store, $branchModels, $key, $pAr, $pEn, $price, array_map(fn ($c) => $categories[$c], $productCats), $presets, $colour, $index++);
            }
        }
    }

    protected function product(Store $store, array $branches, string $key, string $ar, string $en, string $price, array $categoryIds, array $presets, string $colour, int $index): void
    {
        $product = Product::create([
            'store_id' => $store->id, 'name' => ['ar' => $ar, 'en' => $en],
            'description' => ['ar' => "{$ar} — منتج تجريبي.", 'en' => "{$en} — demo product."],
            'image' => $this->image($store->slug, $key, $en, $colour), 'is_active' => true,
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
        foreach ($branches as $branch) {
            // A second branch charges 5 % more (rounded to 5 SYP): branch-specific pricing.
            $branchPrice = $branchIndex === 0 ? $price : (string) (BigDecimal::of($price)->multipliedBy('1.05')->dividedBy(5, 0, RoundingMode::HALF_UP)->multipliedBy(5));
            $branchProduct = BranchProduct::create([
                'branch_id' => $branch->id, 'product_id' => $product->id, 'store_id' => $store->id,
                'price' => $this->base($branchPrice, "{$store->slug}/{$key}"),
                'is_available' => true,
            ]);
            foreach ($options as $optionKey => [$option, $delta]) {
                BranchProductOption::create([
                    'branch_product_id' => $branchProduct->id, 'product_id' => $product->id, 'product_option_id' => $option->id,
                    'price_delta' => $this->base($delta, "{$store->slug}/{$key}/{$optionKey}"),
                    'is_available' => true,
                ]);
            }
            $branchIndex++;
        }
    }

    protected function storeCategoryIds(): array
    {
        $ids = [];
        $sort = 0;
        foreach (DemoCatalogSeeder::STORE_CATEGORIES as $key => [$ar, $en]) {
            $slug = 'demo-'.$key;
            $ids[$key] = (StoreCategory::withTrashed()->where('slug', $slug)->first()
                ?? StoreCategory::create(['name' => ['ar' => $ar, 'en' => $en], 'slug' => $slug, 'sort_order' => ++$sort, 'is_active' => true]))->id;
        }

        return $ids;
    }

    protected function categoryIds(): array
    {
        $ids = [];
        $sort = 100;
        foreach (DemoCatalogSeeder::CATEGORIES + self::EXTRA_CATEGORIES as $slug => [$ar, $en, $parent]) {
            $ids[$slug] = (Category::withTrashed()->where('slug', $slug)->first()
                ?? Category::create(['name' => ['ar' => $ar, 'en' => $en], 'slug' => $slug, 'parent_id' => $parent ? $ids[$parent] : null, 'sort_order' => ++$sort, 'is_active' => true]))->id;
        }

        return $ids;
    }

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

    /* -------------------------------------------------------------- people */

    public static function customerEmail(string $region, int $n): string
    {
        return DemoContext::email(sprintf('%s-customer-%02d', $region, $n));
    }

    public function customer(int $n): User
    {
        return User::query()->where('email', self::customerEmail($this->region['key'], $n))->firstOrFail();
    }

    public function driver(int $n): Driver
    {
        return Driver::query()->where('phone', DemoContext::phone($this->driverPhoneOffset() + $n))->firstOrFail();
    }

    /** Phone n of customer k is DemoContext::phone(customerPhoneOffset + k). */
    abstract protected function customerPhoneOffset(): int;

    abstract protected function driverPhoneOffset(): int;

    protected function people(City $city): void
    {
        foreach ($this->customers() as $n => [$first, $last, $language, $addresses]) {
            $email = self::customerEmail($this->region['key'], $n);
            if (User::withTrashed()->where('email', $email)->exists()) {
                continue;
            }
            $user = User::create([
                'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last,
                'email' => $email, 'email_verified_at' => now(),
                'password' => DemoContext::unusablePassword(),
                'phone' => DemoContext::phone($this->customerPhoneOffset() + $n), 'language' => $language,
                'is_active' => true, 'otp_channel' => 'sms',
            ]);
            $user->forceFill(['phone_verified_at' => now(), 'profile_completed_at' => now()])->save();

            foreach ($addresses as $i => [$labelAr, $labelEn, $centreKey, $north, $east, $lineAr, $lineEn]) {
                [$lat, $lng] = $this->position($centreKey, $north, $east);
                UserAddress::create([
                    'user_id' => $user->id, 'city_id' => $city->id,
                    'label' => $language === 'ar' ? $labelAr : $labelEn,
                    'address_line' => $language === 'ar' ? $lineAr : $lineEn,
                    'details' => $language === 'ar' ? 'عنوان تجريبي' : 'Demo address',
                    'lat' => $lat, 'lng' => $lng, 'is_default' => $i === 0,
                ]);
            }
        }

        foreach ($this->drivers() as $n => [$name, $centreKey, $vehicle, $plate]) {
            $phone = DemoContext::phone($this->driverPhoneOffset() + $n);
            if (Driver::withTrashed()->where('phone', $phone)->exists()) {
                continue;
            }
            Driver::create([
                'name' => $name, 'phone' => $phone, 'password' => DemoContext::unusablePassword(),
                'city_id' => $city->id, 'vehicle_type' => $vehicle, 'vehicle_plate' => $plate,
                'is_active' => true, 'availability' => Driver::AVAILABILITY_AVAILABLE, 'language' => 'ar',
            ]);
        }
    }

    /* -------------------------------------------------------------- orders */

    public function branch(string $storeSlug, string $branchKey): Branch
    {
        $en = $this->stores()[$storeSlug][5][$branchKey][1];

        return Branch::query()->whereHas('store', fn ($query) => $query->where('slug', $storeSlug))->where('name->en', $en)->firstOrFail();
    }

    protected function orders(): void
    {
        $orders = app(OrderAdminService::class);
        $builder = app(DemoOrderBuilder::class);

        foreach ($this->scenarios() as $suffix => [$when, $customerN, $storeSlug, $branchKey, $lines, $options, $steps]) {
            $number = $this->orderPrefix().$suffix;
            if (Order::query()->where('order_number', $number)->exists()) {
                continue;
            }
            $submittedAt = $this->moment($when);
            if (isset($options['scheduled_for'])) {
                $options['scheduled_for'] = $this->moment($options['scheduled_for']);
            }
            if (isset($options['wallet'])) {
                // Typed in NEW pounds; checkout works in stored (old-pound) units.
                $options['wallet'] = (string) Denomination::toStored($options['wallet'], Currency::query()->where('code', 'SYP')->firstOrFail());
            }

            DB::transaction(function () use ($builder, $orders, $number, $submittedAt, $customerN, $storeSlug, $branchKey, $lines, $options, $steps) {
                $user = $this->customer($customerN);
                $branch = $this->branch($storeSlug, $branchKey);
                $address = UserAddress::query()->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get()[$options['address'] ?? 0];
                $checkoutLines = $this->lines($branch, $storeSlug, $lines);
                $order = $this->context->at($submittedAt, fn () => $builder->submitLines($number, $user, $branch, $checkoutLines,
                    ['currency' => 'SYP', 'address_id' => $address->id] + $options));

                foreach ($steps as $step) {
                    [$minutes, $action] = $step;
                    $this->context->at($submittedAt->copy()->addMinutes($minutes), fn () => $this->step($orders, $order->fresh(), $action, $step[2] ?? null));
                }
            });
        }
    }

    /** @return list<array{branch_product_id: int, quantity: int, options: array<int, int>, notes: null}> */
    protected function lines(Branch $branch, string $storeSlug, array $lines): array
    {
        $products = $this->stores()[$storeSlug][6];
        $result = [];
        foreach ($lines as [$productKey, $quantity, $optionKeys]) {
            $productEn = $products[$productKey][1];
            $branchProduct = BranchProduct::query()->where('branch_id', $branch->id)
                ->whereHas('product', fn ($q) => $q->where('name->en', $productEn))->firstOrFail();
            $optionIds = [];
            foreach ($optionKeys as $optionKey) {
                $optionEn = collect(self::OPTION_PRESETS)->map(fn ($preset) => $preset[5][$optionKey][1] ?? null)->filter()->first();
                $optionIds[BranchProductOption::query()->where('branch_product_id', $branchProduct->id)
                    ->whereHas('option', fn ($q) => $q->where('name->en', $optionEn))->valueOrFail('product_option_id')] = 1;
            }
            $result[] = ['branch_product_id' => $branchProduct->id, 'quantity' => $quantity, 'options' => $optionIds, 'notes' => null];
        }

        return $result;
    }

    /** Every status change goes through the real services (state machine, ledger, audit). */
    protected function step(OrderAdminService $orders, Order $order, string $action, mixed $argument): void
    {
        $admin = $this->context->operator();
        $key = 'demo:'.$order->order_number;

        match ($action) {
            'accept' => $orders->accept($order, $admin),
            'assign' => $orders->assignDriver($order, $this->driver($argument), $admin),
            'pickup' => $orders->markPickedUp($order, Driver::findOrFail($order->driver_id)),
            'deliver' => $orders->markDelivered($order, Driver::findOrFail($order->driver_id)),
            'cancel_customer' => $orders->cancelByCustomer($order, $argument, $key.':cancel'),
            'cancel_admin' => $orders->cancelByAdmin($order, $admin, $argument + ['idempotency_key' => $key.':cancel']),
            'request_cancel' => OrderCancellationRequest::create(['order_id' => $order->id, 'customer_note' => $argument]),
            'refund' => app(RefundService::class)->refund(
                OrderPayment::query()->where('order_id', $order->id)->whereHas('method', fn ($q) => $q->where('kind', 'cash'))->firstOrFail(),
                // Typed in NEW pounds; the order's SYP amounts are stored in old pounds.
                Money::toStorage(Denomination::toStored(BigDecimal::of($argument[0]), $order->currency)), $argument[1], $argument[2], $admin, $key.':refund'
            ),
            'rate' => app(OrderDriverRatingService::class)->saveOrderRating($order->user, $order->id, (int) $argument[0], $argument[1]),
        };
    }

    /** [days, hour, minute] in the operations time zone, or minutes relative to the anchor. */
    protected function moment(array|int $when): Carbon
    {
        if (is_int($when)) {
            return $this->context->anchor()->addMinutes($when);
        }
        [$day, $hour, $minute] = $when;

        return $this->context->anchor()->setTimezone(PlatformSettings::timezone())
            ->addDays($day)->setTime($hour, $minute)->setTimezone(config('app.timezone'));
    }

    /** Static demo positions near the drivers' areas so the live map has markers. */
    protected function placeDrivers(): void
    {
        foreach ($this->drivers() as $n => [, $centreKey, , , $availability, $north, $east]) {
            [$lat, $lng] = $this->position($centreKey, $north, $east);
            $this->driver($n)->forceFill([
                'last_lat' => $lat, 'last_lng' => $lng,
                'last_location_at' => $this->context->anchor()->subMinutes(2 + $n),
                'availability' => $availability,
            ])->save();
        }
    }
}
