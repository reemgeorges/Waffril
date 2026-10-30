<?php

namespace Tests\Feature\Localization;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\MarketingBroadcast;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreCategory;
use App\Support\Settings\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-04) — administrator-managed content is stored as {ar, en}
 * (spatie/laravel-translatable): both languages persist, editing one keeps
 * the other, required languages are validated, reads fall back explicitly,
 * and the APIs return the caller's language.
 */
class DatabaseTranslationsTest extends CustomerTestCase
{
    protected function admin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    public function test_updating_one_language_keeps_the_other_and_reads_fall_back(): void
    {
        $category = StoreCategory::firstOrFail();
        $ar = $category->getTranslation('name', 'ar');
        $category->setTranslation('name', 'en', 'Groceries (edited)')->save();
        $this->assertSame($ar, $category->fresh()->getTranslation('name', 'ar'));
        $this->assertSame('Groceries (edited)', $category->fresh()->getTranslation('name', 'en'));

        // Stored as one JSON object holding both languages.
        $raw = json_decode((string) DB::table('store_categories')->where('id', $category->id)->value('name'), true);
        $this->assertSame(['ar', 'en'], array_keys(collect($raw)->sortKeys()->all()));

        // Explicit fallback: an Arabic-only value is shown in English requests (never empty).
        $category->replaceTranslations('name', ['ar' => 'فئة عربية فقط'])->save();
        app()->setLocale('en');
        $this->assertSame('فئة عربية فقط', $category->fresh()->name);
    }

    public function test_filament_product_form_edits_each_language_independently(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin(), 'admin_web');
        $product = Product::where('name->en', 'Chicken shawarma wrap')->firstOrFail();
        $arabic = $product->getTranslation('name', 'ar');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSchemaStateSet(['name' => ['ar' => $arabic, 'en' => 'Chicken shawarma wrap']])
            ->fillForm(['name' => ['ar' => $arabic, 'en' => 'Chicken shawarma roll']])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($arabic, $product->fresh()->getTranslation('name', 'ar'), 'Arabic preserved.');
        $this->assertSame('Chicken shawarma roll', $product->fresh()->getTranslation('name', 'en'));

        // Both languages are required for a product name.
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => ['ar' => '', 'en' => 'Only English']])
            ->call('save')->assertHasFormErrors(['name.ar' => 'required']);
        $this->assertSame($arabic, $product->fresh()->getTranslation('name', 'ar'));
    }

    public function test_apis_return_the_requested_language_for_database_content(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $branch = Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-shami-grill'))->whereHas('city', fn ($q) => $q->where('name->en', 'Damascus'))->firstOrFail();
        $product = Product::where('name->en', 'Chicken shawarma wrap')->firstOrFail();

        foreach (['ar', 'en'] as $locale) {
            $data = $this->api($token)->withHeader('Accept-Language', $locale)
                ->getJson("/api/v1/customer/branches/{$branch->id}/products/{$product->id}")->assertOk()->assertHeader('Content-Language', $locale)
                ->json('data.product');
            $this->assertSame($product->getTranslation('name', $locale), $data['name']);
            $this->assertSame($product->optionGroups()->orderBy('sort_order')->first()->getTranslation('name', $locale), $data['option_groups'][0]['name']);
        }
    }

    public function test_maintenance_message_is_bilingual_and_shown_when_ordering_is_paused(): void
    {
        // A legacy plain-string value is assigned to one language by its script, never invented.
        $this->assertSame(['ar' => 'الطلبات متوقفة مؤقتًا', 'en' => null], PlatformSettings::translations('الطلبات متوقفة مؤقتًا'));
        $this->assertSame(['ar' => null, 'en' => 'Paused for maintenance'], PlatformSettings::translations('Paused for maintenance'));

        Setting::query()->updateOrCreate(['key' => 'orders.accepting_new'], ['value' => false]);
        Setting::query()->updateOrCreate(['key' => 'maintenance.message'], ['value' => ['ar' => 'نجري صيانة قصيرة، عُد بعد قليل.', 'en' => 'Short maintenance, back soon.']]);

        $token = $this->loginCustomer($this->demoCustomer(1));
        $branch = Branch::whereHas('store', fn ($q) => $q->where('slug', 'demo-fresh-market'))->whereHas('city', fn ($q) => $q->where('name->en', 'Damascus'))->firstOrFail();
        $product = $branch->branchProducts()->where('is_available', true)->firstOrFail()->product_id;
        $cart = $this->api($token)->postJson('/api/v1/customer/cart/items', ['branch_id' => $branch->id, 'product_id' => $product, 'quantity' => 1])->assertOk()->json('data.cart');

        foreach (['ar' => 'نجري صيانة قصيرة، عُد بعد قليل.', 'en' => 'Short maintenance, back soon.'] as $locale => $message) {
            $this->api($token)->withHeaders(['Accept-Language' => $locale, 'Idempotency-Key' => (string) Str::uuid()])
                ->postJson("/api/v1/customer/carts/{$cart['id']}/checkout")->assertStatus(409)->assertJsonPath('error', $message);
        }

        // Only Arabic set: English callers get the Arabic text rather than nothing.
        Setting::query()->where('key', 'maintenance.message')->update(['value' => json_encode(['ar' => 'متوقف مؤقتًا'], JSON_UNESCAPED_UNICODE)]);
        $this->assertSame('متوقف مؤقتًا', PlatformSettings::text('maintenance.message', 'en'));
    }

    public function test_marketing_translation_migration_preserves_both_languages_both_ways(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL inside the MySQL test transaction would commit it; verified manually on a disposable MySQL database.');
        }
        $admin = $this->admin();
        DB::table('marketing_broadcasts')->insert(['admin_id' => $admin->id, 'title' => json_encode(['ar' => 'عرض', 'en' => 'Offer']), 'body' => json_encode(['ar' => 'نص', 'en' => 'Text']), 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_11_04_100100_marketing_broadcasts_translatable_text.php');

        $migration->down();
        $legacy = DB::table('marketing_broadcasts')->first();
        $this->assertSame(['عرض', 'Offer', 'نص', 'Text'], [$legacy->title_ar, $legacy->title_en, $legacy->body_ar, $legacy->body_en]);

        $migration->up();
        $row = MarketingBroadcast::firstOrFail();
        $this->assertSame(['ar' => 'عرض', 'en' => 'Offer'], $row->getTranslations('title'));
        $this->assertSame(['ar' => 'نص', 'en' => 'Text'], $row->getTranslations('body'));
        $migration->up(); // idempotent
    }
}
