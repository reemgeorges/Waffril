<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BusinessRuleException;
use App\Models\ContentPage;
use App\Models\Faq;

/**
 * WAFFRLI customer API (2026-11-12) — published app content, readable
 * before sign-in (registration shows the terms). Read-only, throttled per
 * IP, in the request language (Accept-Language). Drafts are never returned.
 * Reading the terms records nothing: there is no automatic consent.
 */
class CustomerContentController extends CustomerController
{
    public function terms()
    {
        return $this->respond(function () {
            $page = ContentPage::query()->where('key', ContentPage::TERMS)->where('is_published', true)->first();
            if (! $page) {
                throw new BusinessRuleException(__('messages.customer.content_not_published'), 404);
            }

            return ['page' => [
                'key' => $page->key,
                'title' => $page->title,
                'body' => $page->body,
                'version' => $page->version,
                'published_at' => $page->published_at?->toIso8601String(),
            ]];
        });
    }

    public function faqs()
    {
        return $this->respond(fn () => ['faqs' => Faq::query()->published()->get()->map(fn (Faq $faq) => [
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
        ])->values()]);
    }
}
