<?php

namespace Tests\Feature\Customer;

use App\Models\HomeSlider;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

class HomeSlidersTest extends CustomerTestCase
{
    public function test_sliders_require_customer_authentication(): void
    {
        $this->getJson('/api/v1/customer/sliders')->assertUnauthorized();
    }

    public function test_global_and_city_matching_store_slides_are_returned_without_leaking_other_city_stores(): void
    {
        $damascus = $this->loginCustomer($this->demoCustomer(1));
        $aleppo = $this->loginCustomer($this->demoCustomer(9));

        Storage::disk('public')->put('demo/sliders/test.jpg', 'test image');
        $base = [
            'title' => ['ar' => 'تجربة', 'en' => 'Demo'],
            'image' => 'demo/sliders/test.jpg',
            'is_active' => true,
        ];

        $global = HomeSlider::create($base + ['sort_order' => 1]);
        $damascusOnly = Store::where('slug', 'demo-fresh-market')->firstOrFail();
        $linked = HomeSlider::create($base + ['store_id' => $damascusOnly->id, 'sort_order' => 2]);
        $inactive = HomeSlider::create(array_merge($base, ['is_active' => false, 'sort_order' => 3]));
        $future = HomeSlider::create($base + ['starts_at' => now()->addDay(), 'sort_order' => 4]);
        $expired = HomeSlider::create($base + ['ends_at' => now()->subMinute(), 'sort_order' => 5]);

        $all = $this->api($damascus)->getJson('/api/v1/customer/sliders')->assertOk()->json('data.sliders');
        $this->assertSame([$global->id, $linked->id], array_column($all, 'id'));
        $this->assertSame('none', $all[0]['action']);
        $this->assertNull($all[0]['store']);
        $this->assertSame('store', $all[1]['action']);
        $this->assertSame($damascusOnly->id, $all[1]['store']['id']);
        $this->assertNotNull($all[1]['store']['branch_id']);
        $this->assertStringContainsString('demo/sliders/test.jpg', $all[1]['image_url']);

        $otherCity = $this->api($aleppo)->getJson('/api/v1/customer/sliders')->assertOk()->json('data.sliders');
        $this->assertSame([$global->id], array_column($otherCity, 'id'));
    }

    public function test_slider_titles_follow_the_request_language(): void
    {
        Storage::disk('public')->put('demo/sliders/test.jpg', 'test image');
        HomeSlider::create(['title' => ['ar' => 'عنوان عربي', 'en' => 'English title'],
            'image' => 'demo/sliders/test.jpg', 'is_active' => true]);
        $token = $this->loginCustomer($this->demoCustomer(1));
        $english = $this->api($token)->getJson('/api/v1/customer/sliders')->assertOk()->json('data.sliders.0.title');
        $this->assertNotEmpty($english);
        $arabic = $this->api($token)->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/customer/sliders')->assertOk()->json('data.sliders.0.title');
        $this->assertSame('عنوان عربي', $arabic);
    }
}
