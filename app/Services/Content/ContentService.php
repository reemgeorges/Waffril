<?php

namespace App\Services\Content;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-11-12) — the only writer of app content (Terms & Conditions,
 * FAQ). Every change needs content.manage and is written to the activity
 * log. A published text that changes gets a new `version` (and publication
 * time) so the app can show "updated terms"; nothing here records or
 * implies customer consent. Final legal wording is entered by the owner.
 */
class ContentService
{
    /** @param array{title: array<string, ?string>, body: array<string, ?string>, is_published?: bool} $data */
    public function savePage(string $key, array $data, Admin $admin, ?ContentPage $page = null): ContentPage
    {
        $this->authorize($admin);
        if (! in_array($key, ContentPage::KEYS, true)) {
            throw new BusinessRuleException(__('admin.content.unknown_page'));
        }

        return DB::transaction(function () use ($key, $data, $admin, $page) {
            $page = $page
                ? ContentPage::query()->lockForUpdate()->findOrFail($page->getKey())
                : ContentPage::withTrashed()->where('key', $key)->lockForUpdate()->first() ?? new ContentPage(['key' => $key]);
            if ($page->trashed()) {
                $page->restore();
            }

            $title = $this->clean($data['title'] ?? []);
            $body = $this->clean($data['body'] ?? [], 60000);
            $publish = (bool) ($data['is_published'] ?? $page->is_published);
            $same = fn (array $a, array $b) => ksort($a) && ksort($b) && $a === $b;
            $textChanged = ! $page->exists
                || ! $same($title, $page->getTranslations('title'))
                || ! $same($body, $page->getTranslations('body'));
            $firstPublication = $publish && ! $page->is_published;

            $page->fill([
                'title' => $title,
                'body' => $body,
                'is_published' => $publish,
                'updated_by_admin_id' => $admin->id,
            ]);
            if ($page->exists && $page->is_published && $publish && $textChanged) {
                $page->version = (int) $page->version + 1;
            }
            if ($publish && ($firstPublication || $textChanged)) {
                $page->published_at = now();
            }
            $created = ! $page->exists;
            $page->save();

            AdminAudit::log($created ? 'content.page_created' : 'content.page_updated', $page, [
                'key' => $key, 'version' => $page->version, 'is_published' => $publish,
            ], $admin);

            return $page->fresh();
        });
    }

    /** @param array{question: array<string, ?string>, answer: array<string, ?string>, sort_order?: int, is_published?: bool} $data */
    public function saveFaq(array $data, Admin $admin, ?Faq $faq = null): Faq
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($data, $admin, $faq) {
            $faq ??= new Faq;
            $created = ! $faq->exists;
            $faq->fill([
                'question' => $this->clean($data['question'] ?? [], 500),
                'answer' => $this->clean($data['answer'] ?? [], 5000),
                'sort_order' => max(0, (int) ($data['sort_order'] ?? $faq->sort_order ?? 0)),
                'is_published' => (bool) ($data['is_published'] ?? $faq->is_published ?? false),
                'updated_by_admin_id' => $admin->id,
            ])->save();
            AdminAudit::log($created ? 'content.faq_created' : 'content.faq_updated', $faq, ['is_published' => $faq->is_published], $admin);

            return $faq->fresh();
        });
    }

    public function setFaqPublished(Faq $faq, bool $published, Admin $admin): Faq
    {
        return $this->saveFaq(['question' => $faq->getTranslations('question'), 'answer' => $faq->getTranslations('answer'), 'is_published' => $published], $admin, $faq);
    }

    public function deleteFaq(Faq $faq, Admin $admin): void
    {
        $this->authorize($admin);
        $faq->delete();
        AdminAudit::log('content.faq_deleted', $faq, [], $admin);
    }

    protected function authorize(Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'content.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }

    /** Arabic and English are both required (the app is bilingual); trimmed, length-capped. */
    protected function clean(array $value, int $max = 200): array
    {
        $out = [];
        foreach (['ar', 'en'] as $locale) {
            $text = trim((string) ($value[$locale] ?? ''));
            if ($text === '') {
                throw new BusinessRuleException(__('admin.content.both_languages_required'));
            }
            $out[$locale] = mb_substr($text, 0, $max);
        }

        return $out;
    }
}
