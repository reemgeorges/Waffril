<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\ContentPages\ContentPageResource;
use App\Filament\Resources\ContentPages\Pages\CreateContentPage;
use App\Filament\Resources\ContentPages\Pages\EditContentPage;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Models\AdminAuditLog;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Services\Content\ContentService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Terms & Conditions and FAQ (2026-11-12): content.manage only, bilingual,
 * draft vs published, versioning of published terms, activity log, and
 * public read endpoints that never expose drafts or record consent.
 */
class AppContentTest extends AdminPanelTestCase
{
    protected function terms(array $overrides = []): array
    {
        return array_merge([
            'title' => ['ar' => 'الشروط والأحكام', 'en' => 'Terms and Conditions'],
            'body' => ['ar' => 'النص العربي المعتمد.', 'en' => 'The approved English text.'],
            'is_published' => false,
        ], $overrides);
    }

    public function test_terms_are_created_published_and_versioned_through_the_panel(): void
    {
        $admin = $this->employee(['content.manage']);
        $this->actingAsAdmin($admin);

        Livewire::test(CreateContentPage::class)
            ->fillForm(['key' => 'terms', ...$this->terms()])
            ->call('create')->assertHasNoFormErrors();
        $page = ContentPage::query()->where('key', 'terms')->sole();
        $this->assertFalse($page->is_published);
        $this->assertSame(1, $page->version);

        // Publishing the first time does not bump the version.
        $page = app(ContentService::class)->savePage('terms', $this->terms(['is_published' => true]), $admin, $page);
        $this->assertTrue($page->is_published);
        $this->assertSame(1, $page->version);
        $this->assertNotNull($page->published_at);

        // Saving the same published text again keeps the version…
        $page = app(ContentService::class)->savePage('terms', $this->terms(['is_published' => true]), $admin, $page);
        $this->assertSame(1, $page->version);

        // …editing a published text raises it.
        Livewire::test(EditContentPage::class, ['record' => $page->getRouteKey()])
            ->assertSchemaStateSet(['title' => ['ar' => 'الشروط والأحكام', 'en' => 'Terms and Conditions']])
            ->fillForm(['body' => ['ar' => 'نص معدّل.', 'en' => 'Edited text.'], 'is_published' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $page->fresh()->version);
        $this->assertSame('Edited text.', $page->fresh()->getTranslation('body', 'en'));

        $this->assertSame(['content.page_created', 'content.page_updated', 'content.page_updated', 'content.page_updated'],
            AdminAuditLog::query()->where('action', 'like', 'content.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_both_languages_are_required(): void
    {
        $this->expectException(BusinessRuleException::class);
        app(ContentService::class)->savePage('terms', $this->terms(['body' => ['ar' => 'نص', 'en' => '']]), $this->employee(['content.manage']));
    }

    public function test_content_needs_the_content_permission(): void
    {
        $this->actingAsAdmin($this->employee(['catalog.manage']));
        $this->assertFalse(ContentPageResource::canViewAny());
        $this->assertFalse(FaqResource::canViewAny());
        $this->get(ContentPageResource::getUrl('index'))->assertForbidden();

        $this->expectException(BusinessRuleException::class);
        app(ContentService::class)->saveFaq(['question' => ['ar' => 'س', 'en' => 'Q'], 'answer' => ['ar' => 'ج', 'en' => 'A']], $this->employee(['catalog.manage']));
    }

    public function test_faqs_are_managed_and_published_from_the_panel(): void
    {
        $this->actingAsAdmin($this->employee(['content.manage']));

        Livewire::test(ManageFaqs::class)
            ->callAction('create', [
                'question' => ['ar' => 'كم يستغرق التوصيل؟', 'en' => 'How long does delivery take?'],
                'answer' => ['ar' => 'عادة أقل من ساعة.', 'en' => 'Usually under an hour.'],
                'sort_order' => 1, 'is_published' => false,
            ])->assertHasNoFormErrors();
        $faq = Faq::query()->sole();
        $this->assertFalse($faq->is_published);

        Livewire::test(ManageFaqs::class)->callAction(TestAction::make('publish')->table($faq));
        $this->assertTrue($faq->fresh()->is_published);

        Livewire::test(ManageFaqs::class)->callAction(TestAction::make('delete')->table($faq));
        $this->assertSoftDeleted($faq);
        $this->assertSame(3, AdminAuditLog::query()->where('action', 'like', 'content.faq_%')->count());
    }

    public function test_public_endpoints_return_published_content_only(): void
    {
        $admin = $this->employee(['content.manage']);
        $this->getJson('/api/v1/customer/content/terms')->assertStatus(404)->assertJsonPath('status', false);

        $service = app(ContentService::class);
        $service->savePage('terms', $this->terms(), $admin); // draft
        $this->getJson('/api/v1/customer/content/terms')->assertStatus(404);

        $service->savePage('terms', $this->terms(['is_published' => true]), $admin);
        $this->getJson('/api/v1/customer/content/terms', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.page.title', 'الشروط والأحكام')
            ->assertJsonPath('data.page.version', 1);
        $this->getJson('/api/v1/customer/content/terms', ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonPath('data.page.body', 'The approved English text.');

        $service->saveFaq(['question' => ['ar' => 'ب', 'en' => 'Second'], 'answer' => ['ar' => 'ب', 'en' => 'B'], 'sort_order' => 2, 'is_published' => true], $admin);
        $service->saveFaq(['question' => ['ar' => 'أ', 'en' => 'First'], 'answer' => ['ar' => 'أ', 'en' => 'A'], 'sort_order' => 1, 'is_published' => true], $admin);
        $service->saveFaq(['question' => ['ar' => 'مسودة', 'en' => 'Draft'], 'answer' => ['ar' => 'م', 'en' => 'D'], 'is_published' => false], $admin);

        $faqs = $this->getJson('/api/v1/customer/content/faqs', ['Accept-Language' => 'en'])->assertOk()->json('data.faqs');
        $this->assertSame(['First', 'Second'], array_column($faqs, 'question'));
    }
}
