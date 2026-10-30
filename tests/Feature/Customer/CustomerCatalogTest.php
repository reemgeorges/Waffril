<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI customer API (2026-11-03) — city-scoped discovery, branch prices,
 * options, search, offers, favorites and verified ratings.
 */
class CustomerCatalogTest extends CustomerTestCase
{
    public function test_only_branches_of_the_delivery_city_are_browsable(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1)); // Damascus address
        $branches = $this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->assertOk()->json('data.branches');
        $cityIds = Branch::whereIn('id', array_column($branches, 'id'))->pluck('city_id')->unique()->values()->all();
        $this->assertCount(1, $cityIds);
        $this->assertSame('Damascus', City::find($cityIds[0])->getTranslation('name', 'en'));

        // The same store has an Aleppo branch: listed independently and not visible from Damascus.
        $aleppoBranch = Branch::whereHas('city', fn ($q) => $q->where('name->en', 'Aleppo'))->firstOrFail();
        $this->assertNotContains($aleppoBranch->id, array_column($branches, 'id'));
        $this->api($token)->getJson("/api/v1/customer/branches/{$aleppoBranch->id}")->assertStatus(404);
        $this->api($token)->getJson("/api/v1/customer/branches/{$aleppoBranch->id}/products")->assertStatus(404);

        $aleppoToken = $this->loginCustomer($this->demoCustomer(9));
        $this->api($aleppoToken)->getJson("/api/v1/customer/branches/{$aleppoBranch->id}")->assertOk()->assertJsonPath('data.branch.id', $aleppoBranch->id);
    }

    public function test_customer_without_an_address_must_choose_one(): void
    {
        $user = $this->demoCustomer(10);
        $user->addresses()->delete();
        $this->api($this->loginCustomer($user))->getJson('/api/v1/customer/branches')->assertStatus(409);
    }

    public function test_branch_prices_options_and_availability_are_branch_specific(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $store = Store::where('slug', 'demo-shami-grill')->firstOrFail();
        [$mazzeh, $oldCity] = Branch::where('store_id', $store->id)->whereHas('city', fn ($q) => $q->where('name->en', 'Damascus'))->orderBy('id')->get()->all();
        $product = Product::where('store_id', $store->id)->where('name->en', 'Chicken shawarma wrap')->firstOrFail();

        $a = $this->api($token)->getJson("/api/v1/customer/branches/{$mazzeh->id}/products/{$product->id}")->assertOk()->json('data.product');
        $b = $this->api($token)->getJson("/api/v1/customer/branches/{$oldCity->id}/products/{$product->id}")->assertOk()->json('data.product');
        $this->assertSame((string) BranchProduct::where('branch_id', $mazzeh->id)->where('product_id', $product->id)->value('price') + 0, (float) $a['price']['amount'] + 0);
        $this->assertNotSame($a['price']['amount'], $b['price']['amount'], 'Branch prices are independent.');
        $this->assertSame('Bread', $a['option_groups'][0]['name']);
        $fries = collect($b['option_groups'])->firstWhere('name', 'Extras')['options'];
        $this->assertFalse(collect($fries)->firstWhere('name', 'Fries')['is_available'], 'Option availability is per branch.');

        // One image per product, aggregates only (no individual comments exposed).
        $this->assertStringContainsString('demo/products/', $a['image_url']);
        $this->assertArrayHasKey('average', $a['rating']);
        $this->assertStringNotContainsString('comment', json_encode($a));
    }

    public function test_listing_avoids_n_plus_one_queries(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $branch = Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-fresh-market'))->firstOrFail();

        DB::enableQueryLog();
        $this->api($token)->getJson("/api/v1/customer/branches/{$branch->id}/products?per_page=50")->assertOk();
        $products = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->api($token)->getJson('/api/v1/customer/branches?per_page=50')->assertOk();
        $branches = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $products, 'Product listing must not issue a query per product.');
        $this->assertLessThan(25, $branches, 'Branch listing must not issue a query per branch.');
    }

    public function test_search_offers_and_categories(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $result = $this->api($token)->getJson('/api/v1/customer/search?q=pizza')->assertOk()->json('data');
        $this->assertNotEmpty($result['branches']);
        $this->assertNotEmpty($result['products']);
        $this->assertTrue(collect($result['products'])->every(fn ($p) => Branch::find($p['branch']['id'])->city->getTranslation('name', 'en') === 'Damascus'));
        $this->api($token)->getJson('/api/v1/customer/search?q=ش')->assertOk()->assertJsonPath('data.products', []);

        $offers = $this->api($token)->getJson('/api/v1/customer/offers')->assertOk()->json('data.offers');
        $this->assertNotEmpty($offers);
        $this->assertTrue(collect($offers)->every(fn ($o) => $o['ends_at'] === null || $o['ends_at'] > now()->toIso8601String()));

        $this->api($token)->getJson('/api/v1/customer/store-categories')->assertOk()->assertJsonCount(5, 'data.store_categories');
        $tree = $this->api($token)->getJson('/api/v1/customer/product-categories')->assertOk()->json('data.categories');
        $this->assertNotEmpty($tree[0]['children']);
    }

    public function test_favorites_are_products_only_and_private(): void
    {
        $user = $this->demoCustomer(2);
        $token = $this->loginCustomer($user);
        $product = Product::firstOrFail();

        $this->api($token)->postJson("/api/v1/customer/products/{$product->id}/favorite")->assertOk();
        $this->api($token)->postJson("/api/v1/customer/products/{$product->id}/favorite")->assertOk(); // idempotent
        $this->assertSame(1, DB::table('favorites')->where('user_id', $user->id)->where('product_id', $product->id)->count());
        $this->assertContains($product->id, array_column($this->api($token)->getJson('/api/v1/customer/favorites')->json('data.favorites'), 'id'));
        $this->api($token)->deleteJson("/api/v1/customer/products/{$product->id}/favorite")->assertOk();
        $this->api($token)->postJson('/api/v1/customer/products/999999/favorite')->assertStatus(404);
    }

    public function test_only_verified_purchasers_can_rate_and_hidden_ratings_do_not_count(): void
    {
        $user = $this->demoCustomer(4);
        $token = $this->loginCustomer($user);
        $order = Order::where('order_number', 'DEMO-0004')->firstOrFail(); // delivered: paracetamol + vitamin C
        $vitamin = Product::where('name->en', 'Vitamin C 1000 mg')->firstOrFail();
        $notBought = Product::where('name->en', 'Power bank 10000 mAh')->firstOrFail();

        $this->api($token)->postJson("/api/v1/customer/products/{$vitamin->id}/rating", ['order_id' => $order->id, 'rating' => 5, 'comment' => 'Works'])
            ->assertOk()->assertJsonPath('data.product_rating.count', 1);
        $this->api($token)->postJson("/api/v1/customer/products/{$notBought->id}/rating", ['order_id' => $order->id, 'rating' => 5])->assertStatus(422);
        $other = Order::where('order_number', 'DEMO-0001')->firstOrFail();
        $this->api($token)->postJson("/api/v1/customer/products/{$vitamin->id}/rating", ['order_id' => $other->id, 'rating' => 5])->assertStatus(404);
        $pending = Order::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->api($token)->postJson("/api/v1/customer/products/{$vitamin->id}/rating", ['order_id' => $pending->id, 'rating' => 4])->assertStatus(422);

        // Hidden (moderated) ratings never count: the power bank's only rating is hidden in the demo data.
        $this->assertSame(1, ProductRating::where('product_id', $notBought->id)->where('is_hidden', true)->count());
        $this->assertSame(0, $notBought->fresh()->rating_count);
    }
}
