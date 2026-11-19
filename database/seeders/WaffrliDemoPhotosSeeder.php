<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use Database\Seeders\Demo\DemoContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Standalone, repeatable photographic demo-media installer for WAFFRLI.
 *
 * Run on an existing LOCAL development database without deleting or creating
 * orders, wallets, payments or accounts:
 *   php artisan db:seed --class="Database\\Seeders\\WaffrliDemoPhotosSeeder"
 *
 * This ONLY updates image/logo paths on existing DEMO records. Non-demo data,
 * manually uploaded images, and existing image files are preserved.
 */
class WaffrliDemoPhotosSeeder extends Seeder
{
    private const DIRECTORY = 'demo/photo-catalog';

    /** All media types shipped with this ZIP. */
    private const TYPES = [
        'shawarma', 'beef', 'grill', 'hummus', 'margherita', 'pepperoni',
        'manakish', 'croissant', 'baklava', 'halawa', 'maamoul', 'salad',
        'juice', 'labneh', 'groceries', 'water', 'pharmacy', 'vitamins',
        'sanitizer', 'baby', 'thermometer', 'masks', 'tech', 'charger',
        'power-bank', 'earbuds', 'eggs', 'rice', 'olive-oil', 'milk',
        'snacks', 'tea', 'cola', 'pita',
    ];

    /** @var array<string, int> */
    private array $counts = ['stores' => 0, 'store_categories' => 0, 'categories' => 0, 'products' => 0, 'protected' => 0];

    public function run(): void
    {
        // Never run this on an exposed production or staging environment.
        DemoContext::assertSafeEnvironment();

        $this->assertMediaPresent();

        DB::transaction(function (): void {
            $stores = Store::query()->where('slug', 'like', 'demo-%')->with('categories')->get();

            foreach ($stores as $store) {
                $type = $this->storeType($store);
                $this->replaceDemoImage('stores', (int) $store->id, 'logo', $store->logo,
                    $this->path('stores', $type), 'stores');
            }

            foreach (StoreCategory::query()->where('slug', 'like', 'demo-%')->get() as $category) {
                $type = $this->categoryType($category->slug);
                $this->replaceDemoImage('store_categories', (int) $category->id, 'image', $category->image,
                    $this->path('categories', $type), 'store_categories');
            }

            foreach (Category::query()->where('slug', 'like', 'demo-%')->get() as $category) {
                $type = $this->categoryType($category->slug);
                $this->replaceDemoImage('categories', (int) $category->id, 'image', $category->image,
                    $this->path('categories', $type), 'categories');
            }

            $storeIds = $stores->pluck('id')->all();
            if ($storeIds !== []) {
                Product::query()->whereIn('store_id', $storeIds)
                    ->with('categories:id,slug')
                    ->chunkById(100, function ($products): void {
                        foreach ($products as $product) {
                            $type = $this->productType(
                                (string) $product->getTranslation('name', 'en'),
                                $product->categories->pluck('slug')->all()
                            );
                            $this->replaceDemoImage('products', (int) $product->id, 'image', $product->image,
                                $this->path('products', $type), 'products');
                        }
                    });
            }
        });

        $this->command?->info('WAFFRLI demo photos: '.json_encode($this->counts, JSON_UNESCAPED_UNICODE));
        $this->command?->info('Existing orders, payments, wallets and all non-demo records were left unchanged.');
    }

    private function path(string $folder, string $type): string
    {
        return self::DIRECTORY.'/'.$folder.'/'.$type.'.jpg';
    }

    private function assertMediaPresent(): void
    {
        $disk = Storage::disk('public');
        $missing = [];
        foreach (['products', 'stores', 'categories'] as $folder) {
            foreach (self::TYPES as $type) {
                $path = $this->path($folder, $type);
                if (! $disk->exists($path)) {
                    $missing[] = $path;
                }
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('WAFFRLI demo photo assets are missing on the PUBLIC disk. '
                .'Extract the ZIP into the project root, preserving storage/app/public. First missing: '
                .implode(', ', array_slice($missing, 0, 5)));
        }
    }

    private function replaceDemoImage(string $table, int $id, string $column, ?string $existing, string $path, string $counter): void
    {
        if ($existing === $path) {
            return; // idempotent
        }
        if (! $this->mayReplace($existing)) {
            $this->counts['protected']++;
            return;
        }

        // Direct query builder update deliberately bypasses BaseModel's old-file
        // cleanup listener. No old SVGs, files or user images are deleted.
        $changed = DB::table($table)->where('id', $id)->update([
            $column => $path,
            'updated_at' => now(),
        ]);
        $this->counts[$counter] += $changed;
    }

    private function mayReplace(?string $current): bool
    {
        if ($current === null || trim($current) === '') {
            return true;
        }

        $current = ltrim(trim($current), '/');

        // Only media from the existing demo seeder or this pack may be replaced.
        return str_starts_with($current, 'demo/products/')
            || str_starts_with($current, 'demo/photo-catalog/');
    }

    private function storeType(Store $store): string
    {
        $slug = strtolower((string) $store->slug);
        if (str_contains($slug, 'pizza')) return 'pepperoni';
        if (str_contains($slug, 'grill')) return 'grill';
        if (str_contains($slug, 'shawarma')) return 'shawarma';
        if (str_contains($slug, 'halawa')) return 'halawa';
        if (str_contains($slug, 'bakery') || str_contains($slug, 'sheaf')) return 'manakish';
        if (str_contains($slug, 'juice')) return 'juice';
        if (str_contains($slug, 'pharmacy') || str_contains($slug, 'dose')) return 'pharmacy';
        if (str_contains($slug, 'tech')) return 'tech';
        if (str_contains($slug, 'market') || str_contains($slug, 'basket')) return 'groceries';
        $categories = $store->categories->pluck('slug')->all();
        if (in_array('demo-restaurants', $categories, true)) return 'grill';
        if (in_array('demo-bakeries', $categories, true)) return 'baklava';
        if (in_array('demo-supermarkets', $categories, true)) return 'groceries';
        if (in_array('demo-pharmacies', $categories, true)) return 'pharmacy';
        return 'tech';
    }

    private function categoryType(string $slug): string
    {
        $slug = strtolower($slug);
        foreach ([
            'pizza' => 'margherita', 'grills' => 'grill', 'sandwiches' => 'shawarma',
            'sweets' => 'baklava', 'bakery' => 'manakish', 'bakeries' => 'croissant',
            'dairy' => 'labneh', 'beverages' => 'juice', 'juices' => 'juice',
            'snacks' => 'snacks', 'medicine' => 'pharmacy', 'pharmacies' => 'pharmacy',
            'health' => 'pharmacy', 'personal-care' => 'sanitizer',
            'electronics' => 'tech', 'accessories' => 'earbuds', 'shops' => 'tech',
            'supermarkets' => 'groceries', 'groceries' => 'groceries',
            'food' => 'grill', 'restaurants' => 'shawarma',
        ] as $keyword => $type) {
            if ($slug === 'demo-'.$keyword) {
                return $type;
            }
        }
        return 'groceries';
    }

    /** @param array<int, string> $categorySlugs */
    private function productType(string $en, array $categorySlugs): string
    {
        $en = mb_strtolower($en);
        foreach ([
            'pepperoni' => 'pepperoni', 'margherita' => 'margherita',
            'pizza' => 'margherita', 'beef shawarma' => 'beef',
            'shawarma' => 'shawarma', 'kebab' => 'grill', 'grill' => 'grill',
            'hummus' => 'hummus', 'fattoush' => 'salad', 'salad' => 'salad',
            'halawet' => 'halawa', 'baklava' => 'baklava', 'namoura' => 'baklava',
            'maamoul' => 'maamoul', 'zaatar' => 'manakish', 'manakish' => 'manakish',
            'lahm bi ajeen' => 'manakish', 'spinach' => 'manakish',
            'croissant' => 'croissant', 'garlic bread' => 'pita',
            'bread' => 'pita', 'kaak' => 'pita',
            'paracetamol' => 'pharmacy', 'ibuprofen' => 'pharmacy',
            'vitamin' => 'vitamins', 'sanitizer' => 'sanitizer',
            'baby wipes' => 'baby', 'thermometer' => 'thermometer',
            'masks' => 'masks',
            'power bank' => 'power-bank', 'usb' => 'charger',
            'charger' => 'charger', 'cable' => 'charger',
            'earbuds' => 'earbuds', 'earphones' => 'earbuds',
            'milk' => 'milk', 'labneh' => 'labneh', 'ayran' => 'labneh',
            'eggs' => 'eggs', 'rice' => 'rice', 'sugar' => 'rice',
            'olive oil' => 'olive-oil', 'water' => 'water',
            'chips' => 'snacks', 'tea' => 'tea', 'cola' => 'cola',
            'soft drink' => 'cola', 'orange' => 'juice',
            'lemon' => 'juice', 'avocado' => 'juice',
            'cocktail' => 'juice', 'fruit' => 'groceries',
        ] as $word => $type) {
            if (str_contains($en, $word)) {
                return $type;
            }
        }
        foreach ($categorySlugs as $slug) {
            $type = $this->categoryType($slug);
            if ($type !== 'groceries') return $type;
        }
        return 'groceries';
    }
}
