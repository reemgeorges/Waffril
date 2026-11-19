<?php

namespace Database\Seeders;

use App\Models\HomeSlider;
use App\Models\Store;
use Database\Seeders\Demo\DemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Safe and repeatable visual-only demo data for Flutter's home carousel.
 * Does NOT call WaffrliDemoSeeder and NEVER touches orders or real users.
 * It never overwrites manually edited sliders or non-demo slider rows.
 */
class WaffrliHomeSliderSeeder extends Seeder
{
    public function run(): void
    {
        DemoContext::assertSafeEnvironment();

        // Each store banner has its own city-specific record. A customer in
        // Hama sees Hama merchants; one in Damascus sees Damascus merchants.
        $patterns = [
            ['welcome', null, 'welcome.jpg', 10,
                ['ar' => 'كل اللي بتحبه بيوصلك', 'en' => 'Your favorites, delivered'],
                ['ar' => 'تسوق واطلب من متاجر مدينتك', 'en' => 'Discover the best in your city']],
            ['hama-grills', 'demo-hama-orontes-grill', 'grills.jpg', 20,
                ['ar' => 'مشاوي العاصي', 'en' => 'Orontes Grill'],
                ['ar' => 'أطيب المشاوي بحماة', 'en' => 'Discover Hama grilled favorites']],
            ['damascus-grills', 'demo-shami-grill', 'grills.jpg', 20,
                ['ar' => 'مشاوي الشامي', 'en' => 'Shami Grill'],
                ['ar' => 'وجبات مشاوي بتشهي', 'en' => 'Grills worth sharing']],
            ['homs-grills', 'demo-homs-clock-tower-grill', 'grills.jpg', 20,
                ['ar' => 'مشاوي الساعة', 'en' => 'Clock Tower Grill'],
                ['ar' => 'شاورما وكباب بحمص', 'en' => 'Shawarma and kebab in Homs']],
            ['hama-pizza', 'demo-hama-corner-pizza', 'pizza.jpg', 30,
                ['ar' => 'بيتزا الزاوية', 'en' => 'Corner Pizza'],
                ['ar' => 'وقت البيتزا', 'en' => 'Pizza time']],
            ['damascus-pizza', 'demo-pizza-roma', 'pizza.jpg', 30,
                ['ar' => 'بيتزا روما', 'en' => 'Pizza Roma'],
                ['ar' => 'اختار البيتزا المفضلة عندك', 'en' => 'Choose your favorite slice']],
            ['hama-market', 'demo-hama-fresh-basket', 'market.jpg', 40,
                ['ar' => 'السلة الطازجة', 'en' => 'Fresh Basket Market'],
                ['ar' => 'احتياجات البيت بكل سهولة', 'en' => 'All your daily essentials']],
            ['damascus-market', 'demo-fresh-market', 'market.jpg', 40,
                ['ar' => 'السوق الطازج', 'en' => 'Fresh Market'],
                ['ar' => 'تسوق براحة', 'en' => 'Shop with ease']],
            ['homs-market', 'demo-homs-daily-market', 'market.jpg', 40,
                ['ar' => 'سوق اليوم', 'en' => 'Daily Market'],
                ['ar' => 'خضرة ومونة للبيت', 'en' => 'Your neighborhood essentials']],
        ];

        $slides = array_map(static fn ($row) => [
            'code' => 'demo-home-'.$row[0],
            'store_slug' => $row[1],
            'image' => 'demo/sliders/'.$row[2],
            'sort_order' => $row[3],
            'title' => $row[4],
            'subtitle' => $row[5],
        ], $patterns);

        $created = 0;
        $skipped = 0;
        foreach ($slides as $data) {
            if (HomeSlider::withTrashed()->where('code', $data['code'])->exists()) {
                $skipped++;
                continue;
            }

            if (! Storage::disk('public')->exists($data['image'])) {
                throw new RuntimeException("Slider demo image is missing: {$data['image']}. Copy the package's storage/ folder into your Laravel project first.");
            }

            $storeId = null;
            if ($data['store_slug'] !== null) {
                $storeId = Store::query()->where('slug', $data['store_slug'])
                    ->where('is_active', true)->value('id');
                if ($storeId === null) {
                    $skipped++;
                    continue; // Store was not seeded in this environment.
                }
            }

            HomeSlider::create([
                'code' => $data['code'],
                'title' => $data['title'],
                'subtitle' => $data['subtitle'],
                'image' => $data['image'],
                'store_id' => $storeId,
                'sort_order' => $data['sort_order'],
                'is_active' => true,
            ]);
            $created++;
        }

        $this->command?->info("WAFFRLI slider demo: {$created} created, {$skipped} skipped. No existing rows or orders changed.");
    }
}
