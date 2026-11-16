<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\City;
use App\Models\Currency;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Currency\OperationalMoney;
use App\Services\Functional\Delivery\DeliveryAreaLocator;
use App\Services\Functional\Delivery\DeliveryFeeResolver;
use App\Services\Model\City\CityService;
use App\Support\Geo\Polygon;
use App\Support\Money\Denomination;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\Demo\DemoHamaSeeder;
use Database\Seeders\Demo\DemoHomsSeeder;
use Database\Seeders\Demo\DemoRegionSeeder;
use Database\Seeders\Demo\Geo\NearestCentrePartition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-11-16) — the Hama demonstration city (main) and Homs:
 * complete delivery-area coverage, honest boundary labels, fee rules,
 * Syrian-pound prices, and the real customer checkout across neighbourhoods.
 */
class HamaDemoDatasetTest extends CustomerTestCase
{
    protected function hama(): City
    {
        return City::query()->where('name->en', 'Hama')->firstOrFail();
    }

    protected function region(): array
    {
        return DemoRegionSeeder::data('hama.json');
    }

    public function test_every_point_of_the_city_is_covered_detected_and_priced(): void
    {
        $data = $this->region();
        $hama = $this->hama();
        $locator = app(DeliveryAreaLocator::class);
        $fees = app(DeliveryFeeResolver::class);
        $cities = app(CityService::class);
        $branch = Branch::query()->where('name->en', 'Az Zanbaqi branch (24 h)')->firstOrFail();

        $probed = 0;
        // ~400 m grid over the city boundary.
        for ($lat = 35.0985; $lat < 35.185; $lat += 0.0036) {
            for ($lng = 36.689; $lng < 36.8165; $lng += 0.0044) {
                if (! NearestCentrePartition::contains($data['boundary'], $lat, $lng)) {
                    continue;
                }
                $area = $locator->locate($hama->id, $lat, $lng);
                $this->assertNotNull($area, "No delivery area at {$lat},{$lng}");
                $this->assertNotSame(DemoRegionSeeder::CITY_AREA_SORT, (int) $area->sort_order, "Only the whole-city fallback covers {$lat},{$lng}: a named area is missing there.");
                $this->assertTrue($fees->quote($hama->id, (float) $branch->lat, (float) $branch->lng, $lat, $lng)->covered, "Fee not covered at {$lat},{$lng}");
                $this->assertSame($hama->id, $cities->detect($lat, $lng)['city']->resource->id ?? $cities->detect($lat, $lng)['city']->id, "GPS city detection at {$lat},{$lng}");
                $probed++;
            }
        }
        $this->assertGreaterThan(400, $probed);
    }

    public function test_areas_are_named_valid_and_honestly_labelled(): void
    {
        $data = $this->region();
        $hama = $this->hama();
        $areas = DeliveryArea::query()->where('city_id', $hama->id)->get();
        $this->assertCount(count($data['centres']) + 1, $areas, 'One whole-city area plus one per OSM centre.');

        $whole = $areas->firstWhere('sort_order', DemoRegionSeeder::CITY_AREA_SORT);
        $this->assertSame('verified', $whole->boundary_quality);
        $this->assertStringContainsString('relation/18354844', $whole->boundary_source);

        $locator = app(DeliveryAreaLocator::class);
        foreach ($data['centres'] as $key => $centre) {
            $area = $locator->locate($hama->id, (float) $centre['lat'], (float) $centre['lng']);
            $this->assertSame($centre['en'], $area->getTranslation('name', 'en'), "Centre {$key} resolves to its own area.");
            $this->assertSame($centre['ar'], $area->getTranslation('name', 'ar'));
            $this->assertSame('approximate', $area->boundary_quality, 'Nearest-centre shapes are never presented as official.');
            $this->assertStringContainsString($centre['osm'], $area->boundary_source);
        }
        foreach ($areas as $area) {
            $this->assertNull(Polygon::validationError($area->boundary), "Area {$area->id} must be a valid closed ring.");
        }

        // Homs: no OSM city boundary, so its whole area is labelled approximate; five verified neighbourhoods.
        $homs = City::query()->where('name->en', 'Homs')->firstOrFail();
        $this->assertSame('approximate', DeliveryArea::query()->where('city_id', $homs->id)->where('sort_order', DemoRegionSeeder::CITY_AREA_SORT)->value('boundary_quality'));
        $this->assertSame(5, DeliveryArea::query()->where('city_id', $homs->id)->where('boundary_quality', 'verified')->count());
    }

    /** @return array{0: string, 1: array} [token, cart] */
    /** @var array<int, string> one login per customer (the OTP endpoints are rate limited) */
    protected array $tokens = [];

    protected function cartAt(User $user, string $centreKey, Branch $branch, string $productEn, int $quantity = 1): array
    {
        $centre = $this->region()['centres'][$centreKey];
        $token = $this->tokens[$user->id] ??= $this->loginCustomer($user);
        $this->api($token)->postJson('/api/v1/customer/addresses', [
            'label' => $centre['en'], 'address_line' => 'Test address in '.$centre['en'],
            'lat' => (float) $centre['lat'] + 0.0003, 'lng' => (float) $centre['lng'] + 0.0002, 'is_default' => true,
        ])->assertOk();
        $bp = BranchProduct::query()->where('branch_id', $branch->id)->whereHas('product', fn ($q) => $q->where('name->en', $productEn))->firstOrFail();
        $options = BranchProductOption::query()->where('branch_product_id', $bp->id)->whereHas('option.group', fn ($q) => $q->where('min_select', '>', 0))
            ->get()->groupBy(fn ($o) => $o->option->option_group_id)->map(fn ($g) => ['id' => $g->first()->product_option_id, 'quantity' => 1])->values()->all();
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', [
            'branch_id' => $branch->id, 'product_id' => $bp->product_id, 'quantity' => $quantity, 'options' => $options,
        ])->assertOk()->json('data.cart');

        return [$token, $cart];
    }

    protected function checkoutCart(string $token, int $cartId)
    {
        return $this->api($token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/customer/carts/{$cartId}/checkout");
    }

    public function test_cash_checkout_works_across_hama_neighbourhoods(): void
    {
        // As in production: Syrian pounds are the operational currency (the suite pins USD).
        config(['waffrli.currency.operational_default' => 'SYP']);
        $user = User::query()->where('email', DemoRegionSeeder::customerEmail('hama', 3))->firstOrFail();
        $branch = Branch::query()->where('name->en', 'Az Zanbaqi branch (24 h)')->firstOrFail();
        $syp = Currency::query()->where('code', 'SYP')->firstOrFail();
        $hama = $this->hama();
        $cityRules = DeliveryFeeRule::query()->where('city_id', $hama->id)->whereNull('delivery_area_id')->pluck('id')->all();

        // Old centre (own fee band), outer villages west/north-east, south suburb, east.
        foreach (['zanbaqi', 'kazu', 'jibrin', 'dahiyat-abi-al-fidaa', 'souq-al-ghanam', 'mashaa-al-arbaeen'] as $centreKey) {
            [$token, $cart] = $this->cartAt($user, $centreKey, $branch, 'Chicken shawarma sandwich', 2);
            $this->assertTrue($cart['preview']['delivery_covered'], "Covered at {$centreKey}");
            $response = $this->checkoutCart($token, $cart['id'])->assertOk();

            $order = Order::query()->findOrFail($response->json('data.order.id'));
            $this->assertSame($syp->id, $order->currency_id, 'Orders are in Syrian pounds.');
            $this->assertSame($this->region()['centres'][$centreKey]['en'], $order->deliveryArea?->getTranslation('name', 'en'), "Delivery area at {$centreKey}");
            if ($centreKey === 'zanbaqi') {
                $this->assertNotContains($order->delivery_fee_rule_id, $cityRules, 'The old centre uses its own area rule.');
            } else {
                $this->assertContains($order->delivery_fee_rule_id, $cityRules, "City-wide rule at {$centreKey}");
            }
            $this->assertTrue(BigDecimal::of((string) $order->total)->isEqualTo(BigDecimal::of((string) $order->subtotal)->minus((string) $order->offer_discount_total)->minus((string) $order->plus_discount_amount)->plus((string) $order->delivery_fee)));
            // The API shows exactly the stored total in NEW pounds.
            $this->assertSame((string) Denomination::present((string) $order->total, $syp)->toScale(2), (string) BigDecimal::of((string) $response->json('data.order.amounts.total'))->toScale(2));
        }
    }

    public function test_minimum_order_city_mismatch_and_opening_hours_are_enforced(): void
    {
        $user = User::query()->where('email', DemoRegionSeeder::customerEmail('hama', 4))->firstOrFail();

        // Minimum order 300 SYP at Fresh Basket: one bag of chips (50 SYP) is refused.
        $market = Branch::query()->where('name->en', 'Ash Shahba branch')->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00', 'Asia/Damascus'));
        [$token, $cart] = $this->cartAt($user, 'shahba', $market, 'Potato chips');
        $this->assertFalse($cart['preview']['meets_min_order']);
        $this->checkoutCart($token, $cart['id'])->assertStatus(422);

        // A Homs branch is not in a Hama customer's city.
        $homsBranch = Branch::query()->whereHas('store', fn ($q) => $q->where('slug', 'demo-homs-orontes-pharmacy'))->firstOrFail();
        $product = BranchProduct::query()->where('branch_id', $homsBranch->id)->firstOrFail();
        $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $homsBranch->id, 'product_id' => $product->product_id, 'quantity' => 1])->assertStatus(404);

        // The bakery closes 14:00–17:00: an immediate order at 15:30 is refused, at 18:00 accepted.
        $bakery = Branch::query()->where('name->en', 'Al Hamidiyah branch')->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:30', 'Asia/Damascus'));
        [$token, $cart] = $this->cartAt($user, 'hamidiyah', $bakery, 'Zaatar manakish', 3);
        $this->checkoutCart($token, $cart['id'])->assertStatus(422);
        Carbon::setTestNow(Carbon::parse('2026-11-18 18:00', 'Asia/Damascus'));
        $this->checkoutCart($token, $cart['id'])->assertOk();
        Carbon::setTestNow();
    }

    public function test_demo_prices_are_exact_syrian_pound_amounts(): void
    {
        // The suite pins USD as the operational currency; show prices in SYP here.
        config(['waffrli.currency.operational_default' => 'SYP']);
        OperationalMoney::flush();
        $syp = Currency::query()->where('code', 'SYP')->firstOrFail();
        $storeIds = Store::query()->where('slug', 'like', 'demo-hama-%')->orWhere('slug', 'like', 'demo-homs-%')->pluck('id');
        $this->assertGreaterThanOrEqual(11, $storeIds->count());

        $checked = 0;
        foreach (BranchProduct::query()->whereIn('store_id', $storeIds)->with('options')->get() as $bp) {
            foreach (array_merge([(string) $bp->price], $bp->options->pluck('price_delta')->map(fn ($v) => (string) $v)->all()) as $base) {
                // What people see: the stored SYP (old pounds) in NEW pounds.
                $shown = Denomination::present(OperationalMoney::fromBase($base), $syp);
                $this->assertTrue($shown->isEqualTo($shown->toScale(0, RoundingMode::DOWN)), "Whole pounds: {$shown}");
                $this->assertSame(0, $shown->toBigInteger()->mod(5)->toInt(), "Shop-like amount (multiple of 5 SYP): {$shown}");
                $checked++;
            }
        }
        $this->assertGreaterThan(80, $checked);
        $this->assertSame('SYP', $syp->code);
    }

    public function test_rerunning_adds_nothing_and_never_overwrites(): void
    {
        $count = fn () => [DeliveryArea::count(), Store::count(), Branch::count(), BranchProduct::count(), User::count(), Order::count(), DeliveryFeeRule::count()];
        $area = DeliveryArea::query()->where('city_id', $this->hama()->id)->where('name->en', 'Az Zanbaqi')->firstOrFail();
        $area->forceFill(['is_active' => false, 'name' => ['ar' => 'الزنبقي (معدّل)', 'en' => 'Az Zanbaqi']])->save();
        $before = $count();

        $this->seed(DemoHamaSeeder::class);
        $this->seed(DemoHomsSeeder::class);

        $this->assertSame($before, $count());
        $this->assertSame('الزنبقي (معدّل)', $area->fresh()->getTranslation('name', 'ar'), 'An admin edit is never overwritten.');
        $this->assertFalse($area->fresh()->is_active);
    }
}
