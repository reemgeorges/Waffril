<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Http\Middleware\SetAdminPanelLocale;
use App\Models\Admin;
use App\Models\PlusRequest;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-16) — the admin's language must survive Livewire AJAX
 * requests (status tabs, filters, sorting, pagination, actions, realtime
 * refreshes), not only the first page load.
 *
 * These tests send REAL HTTP requests to Livewire's update endpoint, through
 * the same middleware stack a browser uses. Livewire::test() bypasses route
 * middleware, which is why the earlier tests (that set the locale by hand)
 * never caught the regression: SetAdminPanelLocale was registered as a
 * normal panel middleware, so Livewire update requests ran in the default
 * language and the table re-rendered in English after a tab or filter
 * change.
 */
class AdminLivewireLocaleTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    /** The language a fresh PHP process starts in (App::setLocale() also rewrites config('app.locale')). */
    protected string $processDefaultLocale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processDefaultLocale = (string) config('app.locale');
    }

    /** @return array{0: string, 1: string} [snapshot json, component name] */
    protected function snapshotOf(TestResponse $page, string $needle): array
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $json = html_entity_decode($encoded, ENT_QUOTES);
            $snapshot = json_decode($json, true);
            if (str_contains((string) ($snapshot['memo']['name'] ?? ''), $needle)) {
                return [$json, $snapshot['memo']['name']];
            }
        }
        $this->fail("No Livewire component matching [{$needle}] on the page.");
    }

    /** One Livewire round trip, exactly like the browser sends it. */
    protected function livewire(string $snapshot, array $updates = [], array $calls = []): string
    {
        // A real Livewire request runs in a fresh PHP process that starts in
        // the configured default language. Tests share one application
        // instance, so the locale of the previous request must not leak in.
        app()->setLocale($this->processDefaultLocale);
        // ...and Livewire's per-request state (it skips re-applying persistent
        // middleware for the same route until a request ends).
        \Livewire\trigger('flush-state');

        $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
        ]);
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    protected function adminSpeaking(string $locale): Admin
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['locale' => $locale])->save();

        return $admin;
    }

    protected function seedOrders(): void
    {
        $branch = $this->branch($this->store(), $this->city());
        $user = $this->customer();
        $this->order($user, $branch, '10');
        $this->order($user, $branch, '12', ['status' => OrderStatus::Delivered]);
    }

    /** Tab labels are built while the page component boots: the first thing to go wrong. */
    protected function assertTabsIn(string $locale, string $html, array $keys, string $step): void
    {
        $other = $locale === 'ar' ? 'en' : 'ar';
        foreach ($keys as $key) {
            $this->assertStringContainsString(e(__($key, [], $locale)), $html, "[{$step}] tab {$key} must be in {$locale}");
            $this->assertStringNotContainsString(e(__($key, [], $other)), $html, "[{$step}] tab {$key} must not be in {$other}");
        }
    }

    public function test_orders_stay_arabic_across_tabs_filters_and_refreshes(): void
    {
        $this->seedOrders();
        $this->actingAsAdmin($this->adminSpeaking('ar'));

        $page = $this->get('/admin/orders')->assertOk()->assertSee('dir="rtl"', false);
        [$snapshot] = $this->snapshotOf($page, 'ListOrders');
        $tabs = ['admin.dispatch.tab_all', 'admin.orders.tab_pending', 'admin.orders.tab_on_the_road'];
        $ar = trans('admin.order_status', [], 'ar');
        $en = trans('admin.order_status', [], 'en');

        // 1. Switch status tab (Livewire property update, as the tab button does).
        $html = $this->livewire($snapshot, ['activeTab' => 'delivered'], [['path' => '', 'method' => '$set', 'params' => []]]);
        $this->assertTabsIn('ar', $html, $tabs, 'tab delivered');
        $this->assertStringContainsString($ar['delivered'], $html, 'Delivered badge in Arabic after switching tab.');
        $this->assertStringNotContainsString('>'.$en['delivered'].'<', $html);

        // 2. Apply a status filter, then reset it.
        $html = $this->livewire($snapshot, ['activeTab' => 'all', 'tableFilters.status.values' => ['pending']]);
        $this->assertTabsIn('ar', $html, $tabs, 'filter applied');
        $this->assertStringContainsString($ar['pending'], $html);
        $html = $this->livewire($snapshot, ['tableFilters.status.values' => []]);
        $this->assertTabsIn('ar', $html, $tabs, 'filter removed');

        // 3. A plain refresh (what a realtime event triggers).
        $html = $this->livewire($snapshot, [], [['path' => '', 'method' => '$refresh', 'params' => []]]);
        $this->assertTabsIn('ar', $html, $tabs, 'refresh');
        $this->assertStringContainsString($ar['pending'], $html);
    }

    public function test_orders_stay_english_for_an_english_admin_even_when_the_default_is_arabic(): void
    {
        config(['app.locale' => 'ar']);
        $this->processDefaultLocale = 'ar';
        $this->seedOrders();
        $this->actingAsAdmin($this->adminSpeaking('en'));

        $page = $this->get('/admin/orders')->assertOk()->assertSee('dir="ltr"', false);
        [$snapshot] = $this->snapshotOf($page, 'ListOrders');

        $html = $this->livewire($snapshot, ['activeTab' => 'pending']);
        $this->assertTabsIn('en', $html, ['admin.dispatch.tab_all', 'admin.orders.tab_pending'], 'tab pending');
        $this->assertStringContainsString(trans('admin.order_status.pending', [], 'en'), $html);
    }

    public function test_plus_requests_stay_arabic_after_a_livewire_request(): void
    {
        $user = $this->customer();
        PlusRequest::create(['user_id' => $user->id, 'type' => 'custom', 'customer_note' => 'x', 'source' => 'customer']);
        $this->actingAsAdmin($this->adminSpeaking('ar'));

        $page = $this->get('/admin/plus-requests')->assertOk();
        [$snapshot] = $this->snapshotOf($page, 'ListPlusRequests');

        $html = $this->livewire($snapshot, ['activeTab' => 'approved']);
        $this->assertTabsIn('ar', $html, ['admin.requests.status_pending', 'admin.requests.status_approved', 'admin.requests.status_rejected'], 'PLUS tab');
        $html = $this->livewire($snapshot, ['activeTab' => 'pending']);
        $this->assertStringContainsString(e(trans('admin.requests.status_pending', [], 'ar')), $html, 'PLUS request status in Arabic after a Livewire request.');
    }

    public function test_the_admin_locale_middleware_is_livewire_persistent(): void
    {
        $this->assertContains(SetAdminPanelLocale::class, Livewire::getPersistentMiddleware());
    }
}
