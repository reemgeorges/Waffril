<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Models\Admin;
use App\Models\City;
use App\Models\Driver;
use App\Models\MarketingBroadcast;
use App\Models\User;
use App\Services\Notifications\MarketingBroadcastService;
use App\Support\Broadcasting\RecordingBroadcaster;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-16, Phase 12) — marketing messages to customers, drivers
 * or both, optionally limited to one city, with live progress counters.
 * The previous behaviour (customers only) stays the default.
 */
class MarketingAudienceTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function sender(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->givePermissionTo('notifications.marketing');

        return $admin;
    }

    private function send(array $extra = []): MarketingBroadcast
    {
        return app(MarketingBroadcastService::class)->create($this->sender(), array_merge([
            'title' => ['ar' => 'عرض', 'en' => 'Offer'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
        ], $extra))->fresh();
    }

    private function received(string $morph): int
    {
        return DatabaseNotification::query()->where('type', 'marketing.message')->where('notifiable_type', $morph)->count();
    }

    private function activeCustomers()
    {
        return User::query()->whereNull('anonymized_at')->where('is_active', true)->where('account_status', '!=', User::ACCOUNT_SUSPENDED);
    }

    public function test_default_is_customers_only_as_before(): void
    {
        $broadcast = $this->send();

        $this->assertSame(MarketingBroadcast::RECIPIENTS_CUSTOMERS, $broadcast->recipient_type);
        $this->assertSame($this->activeCustomers()->count(), $broadcast->recipients_count);
        $this->assertSame(0, $this->received((new Driver)->getMorphClass()));
    }

    public function test_drivers_only_reaches_active_drivers_and_no_customer(): void
    {
        $inactive = Driver::query()->firstOrFail();
        $inactive->forceFill(['is_active' => false])->save();
        $deleted = Driver::query()->where('is_active', true)->firstOrFail();
        $deleted->delete();

        $broadcast = $this->send(['recipient_type' => 'drivers']);

        $expected = Driver::query()->where('is_active', true)->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $broadcast->recipients_count);
        $this->assertSame($expected, $this->received((new Driver)->getMorphClass()));
        $this->assertSame(0, $this->received((new User)->getMorphClass()));
        $this->assertSame(0, $inactive->notifications()->where('type', 'marketing.message')->count());
        $this->assertSame(0, DatabaseNotification::where('type', 'marketing.message')->where('notifiable_id', $deleted->id)->where('notifiable_type', (new Driver)->getMorphClass())->count());
    }

    public function test_both_limited_to_one_city(): void
    {
        $city = City::query()->whereHas('drivers')->firstOrFail();
        $broadcast = $this->send(['recipient_type' => 'both', 'audience' => 'city', 'city_id' => $city->id]);

        $customers = $this->activeCustomers()->whereHas('addresses', fn ($a) => $a->where('is_default', true)->where('city_id', $city->id))->count();
        $drivers = Driver::query()->where('is_active', true)->where('city_id', $city->id)->count();
        $this->assertGreaterThan(0, $customers + $drivers);
        $this->assertSame($customers + $drivers, $broadcast->recipients_count);
        $this->assertSame($drivers, $this->received((new Driver)->getMorphClass()));
        $this->assertSame($customers, $this->received((new User)->getMorphClass()));
    }

    public function test_progress_counters_and_live_events(): void
    {
        config(['waffrli.push.marketing_chunk' => 2]);
        $broadcast = $this->send(['recipient_type' => 'both']);

        $this->assertSame(MarketingBroadcast::STATUS_SENT, $broadcast->status);
        $this->assertSame($broadcast->recipients_count, $broadcast->total_count);
        $this->assertSame($broadcast->recipients_count, $broadcast->processed_count);

        $events = array_filter(RecordingBroadcaster::on('private-admin.activity', 'admin.operational'),
            fn ($m) => $m['payload']['type'] === 'marketing.progress' && $m['payload']['id'] === $broadcast->id);
        // Start + one per chunk (of 2) + done: several live updates, identifiers only.
        $this->assertGreaterThanOrEqual(3, count($events));
        foreach ($events as $event) {
            $this->assertSame(['type', 'id', 'reference', 'at'], array_keys($event['payload']));
        }
    }

    public function test_unknown_recipient_type_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->send(['recipient_type' => 'everyone']);
    }

    public function test_panel_form_sends_to_drivers_in_a_city(): void
    {
        $city = City::query()->whereHas('drivers')->firstOrFail();
        $this->actingAs($this->sender(), 'admin_web');

        Livewire::test(ListMarketingBroadcasts::class)
            ->callAction(TestAction::make('send'), [
                'title' => ['ar' => 'مرحبا', 'en' => 'Hello'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
                'recipient_type' => 'drivers', 'audience' => 'city', 'city_id' => $city->id,
            ])
            ->assertHasNoFormErrors()
            ->assertSee(__('admin.marketing.recipients_drivers'));

        $broadcast = MarketingBroadcast::sole();
        $this->assertSame('drivers', $broadcast->recipient_type);
        $this->assertSame($city->id, $broadcast->city_id);
        $this->assertSame(Driver::query()->where('is_active', true)->where('city_id', $city->id)->count(), $broadcast->recipients_count);
    }
}
