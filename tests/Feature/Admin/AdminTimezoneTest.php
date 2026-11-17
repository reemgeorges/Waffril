<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\RelationManagers\OpeningHoursRelationManager;
use App\Filament\Resources\DriverCashSettlements\Pages\ListDriverCashSettlements;
use App\Filament\Resources\Offers\Pages\ManageOffers;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Http\Middleware\SetAdminDisplayTimezone;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Time\AdminTimezone;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-17) — every admin sees panel times in their own browser
 * timezone (IANA, from the waffrli_tz cookie), on full pages and Livewire
 * updates; date filters use the admin's local days; storage stays UTC and
 * historical values are never rewritten; the mobile API is unaffected.
 */
class AdminTimezoneTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        AdminTimezone::reset();
        config(['waffrli.admin_display_timezone' => 'UTC']);
        $this->order = $this->order($this->customer(), $this->branch($this->store(), $this->city()), '5');
        // The reported case: stored 19:35 UTC = 22:35 in Damascus.
        DB::table('orders')->where('id', $this->order->id)->update(['created_at' => '2026-11-16 19:35:00']);
    }

    protected function tearDown(): void
    {
        AdminTimezone::reset();
        FilamentTimezone::set(null);
        parent::tearDown();
    }

    /** A fresh request as a browser in $zone would send it. */
    private function pageIn(?string $zone, string $url): TestResponse
    {
        AdminTimezone::reset();
        FilamentTimezone::set(null);
        \Livewire\trigger('flush-state');
        $this->unencryptedCookies = [];   // the test client keeps cookies between requests
        $request = $zone ? $this->withUnencryptedCookie(AdminTimezone::COOKIE, $zone) : $this;

        return $request->get($url)->assertOk();
    }

    public function test_the_same_instant_shows_in_each_admins_own_zone_and_storage_is_untouched(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        $url = '/admin/orders/'.$this->order->id;

        $damascus = (string) $this->pageIn('Asia/Damascus', $url)->getContent();
        $this->assertStringContainsString('22:35', $damascus);
        $this->assertStringNotContainsString('19:35', $damascus);

        // A second admin in Los Angeles (UTC-8 in November) at the same time.
        $this->actingAsAdmin($this->superAdmin());
        $losAngeles = (string) $this->pageIn('America/Los_Angeles', $url)->getContent();
        $this->assertStringContainsString('11:35', $losAngeles);
        $this->assertStringNotContainsString('22:35', $losAngeles);

        // No cookie yet: the documented fallback (here UTC); the page script
        // carries the zone it rendered with, to correct itself once.
        $fallback = (string) $this->pageIn(null, $url)->getContent();
        $this->assertStringContainsString('19:35', $fallback);
        $this->assertStringContainsString('waffrli_tz', $fallback);

        // An invalid cookie never breaks the page and falls back.
        $this->assertStringContainsString('19:35', (string) $this->pageIn('Mars/Olympus', $url)->getContent());

        $this->assertSame('2026-11-16 19:35:00', (string) DB::table('orders')->where('id', $this->order->id)->value('created_at'), 'Stored UTC value unchanged.');
    }

    public function test_livewire_updates_keep_the_admins_zone(): void
    {
        $this->assertContains(SetAdminDisplayTimezone::class, Livewire::getPersistentMiddleware());
        $this->actingAsAdmin($this->superAdmin());

        $page = $this->pageIn('Asia/Damascus', '/admin/orders');
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $m);
        $snapshot = collect($m[1])->map(fn ($e) => html_entity_decode($e, ENT_QUOTES))
            ->first(fn ($json) => str_contains((string) (json_decode($json, true)['memo']['name'] ?? ''), 'ListOrders'));
        $this->assertNotNull($snapshot);

        // A fresh Livewire request (as a new PHP process would see it), carrying the cookie.
        AdminTimezone::reset();
        FilamentTimezone::set(null);
        \Livewire\trigger('flush-state');
        // withCredentials(): the test client only sends cookies with JSON requests
        // when asked; browsers always send them with Livewire's same-origin fetch.
        $html = (string) $this->withCredentials()->withUnencryptedCookie(AdminTimezone::COOKIE, 'Asia/Damascus')
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
            ->assertOk()->json('components.0.effects.html');
        $this->assertStringContainsString('22:35', $html, 'The created-at tooltip re-rendered in Damascus time.');
        $this->assertStringNotContainsString('19:35', $html);
    }

    public function test_date_filters_use_the_admins_local_days_across_midnight(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        // 2026-11-16 22:30 UTC is already 17 Nov 01:30 in Damascus, still 16 Nov 14:30 in Los Angeles.
        DB::table('orders')->where('id', $this->order->id)->update(['created_at' => '2026-11-16 22:30:00']);
        $order = $this->order->fresh();

        config(['waffrli.admin_display_timezone' => 'Asia/Damascus']);
        AdminTimezone::reset();
        Livewire::test(ListOrders::class)->filterTable('created_between', ['from' => '2026-11-17', 'until' => '2026-11-17'])->assertCanSeeTableRecords([$order]);
        Livewire::test(ListOrders::class)->filterTable('created_between', ['until' => '2026-11-16'])->assertCanNotSeeTableRecords([$order]);

        config(['waffrli.admin_display_timezone' => 'America/Los_Angeles']);
        AdminTimezone::reset();
        Livewire::test(ListOrders::class)->filterTable('created_between', ['from' => '2026-11-16', 'until' => '2026-11-16'])->assertCanSeeTableRecords([$order]);
        Livewire::test(ListOrders::class)->filterTable('created_between', ['from' => '2026-11-17'])->assertCanNotSeeTableRecords([$order]);
    }

    public function test_local_days_follow_daylight_saving_rules(): void
    {
        config(['waffrli.admin_display_timezone' => 'America/Los_Angeles']);
        AdminTimezone::reset();
        // Spring forward (8 Mar 2026): a 23-hour local day.
        $this->assertSame('2026-03-08 08:00:00', AdminTimezone::dayStartUtc('2026-03-08')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 07:00:00', AdminTimezone::dayEndUtc('2026-03-08')->format('Y-m-d H:i:s'));
        // Fall back (1 Nov 2026): a 25-hour local day.
        $this->assertSame('2026-11-01 07:00:00', AdminTimezone::dayStartUtc('2026-11-01')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 08:00:00', AdminTimezone::dayEndUtc('2026-11-01')->format('Y-m-d H:i:s'));
        // Display across the change: same wall clock, different offsets.
        $this->assertSame('12:00', AdminTimezone::local(Carbon::parse('2026-03-07 20:00:00', 'UTC'))->format('H:i'));
        $this->assertSame('13:00', AdminTimezone::local(Carbon::parse('2026-03-08 20:00:00', 'UTC'))->format('H:i'));

        config(['waffrli.admin_display_timezone' => 'Asia/Damascus']);
        AdminTimezone::reset();
        $this->assertSame('2026-11-04 21:00:00', AdminTimezone::dayStartUtc('2026-11-05')->format('Y-m-d H:i:s'));
    }

    public function test_date_time_inputs_are_entered_locally_and_stored_in_utc(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        FilamentTimezone::set('Asia/Damascus');

        $product = Product::query()->firstOrFail();
        Livewire::test(ManageOffers::class)
            ->callAction(TestAction::make('create'), [
                'store_id' => $product->store_id, 'product_id' => $product->id,
                'discount_type' => 'percent', 'discount_value' => 10,
                'starts_at' => '2026-11-20 10:00:00', 'ends_at' => '2026-11-21 10:00:00', 'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $offer = Offer::query()->latest('id')->firstOrFail();
        $this->assertSame('2026-11-20 07:00:00', (string) DB::table('offers')->where('id', $offer->id)->value('starts_at'), 'Local 10:00 in Damascus is stored as 07:00 UTC.');
    }

    public function test_the_mobile_api_is_not_affected_by_the_panel_cookie(): void
    {
        $user = $this->order->user;
        $token = $user->createToken('t', ['user', '*'])->plainTextToken;
        $plain = $this->withToken($token)->getJson('/api/v1/customer/orders/'.$this->order->id)->json('data.order.created_at');
        $this->app['auth']->forgetGuards();
        $withCookie = $this->withUnencryptedCookie(AdminTimezone::COOKIE, 'America/Los_Angeles')->withToken($token)
            ->getJson('/api/v1/customer/orders/'.$this->order->id)->json('data.order.created_at');

        $this->assertSame($plain, $withCookie);
        $this->assertStringEndsWith('+00:00', (string) $plain);
    }

    public function test_wall_clock_hours_and_calendar_dates_are_never_shifted(): void
    {
        $this->actingAsAdmin($this->superAdmin());
        FilamentTimezone::set('America/Los_Angeles');
        $branch = $this->order->branch;
        $branch->openingHours()->delete();

        // Opening hours are the branch's own wall clock, not instants.
        Livewire::test(OpeningHoursRelationManager::class, [
            'ownerRecord' => $branch, 'pageClass' => EditBranch::class,
        ])->callAction(TestAction::make('create')->table(), ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00'])
            ->assertHasNoActionErrors();
        $hours = $branch->openingHours()->where('day_of_week', 1)->firstOrFail();
        $this->assertSame('09:00', substr((string) $hours->getRawOriginal('opens_at'), 0, 5));
        $this->assertSame('17:00', substr((string) $hours->getRawOriginal('closes_at'), 0, 5));

        // A business date (DATE column) shows the same calendar day in any zone.
        $table = Livewire::test(ListDriverCashSettlements::class)->instance()->getTable();
        $this->assertSame('UTC', $table->getColumn('business_date')->getTimezone(), 'Never shifted to the previous day west of UTC.');
        // ...while real timestamps on the same screen do follow the admin.
        $this->assertSame('America/Los_Angeles', TextColumn::make('created_at')->dateTime()->getTimezone());
    }
}
