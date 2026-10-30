<?php

namespace Tests\Feature\Realtime;

use App\Filament\Pages\DriverMapPage;
use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Models\Admin;
use App\Models\MarketingBroadcast;
use App\Models\Order;
use App\Models\User;
use App\Support\Broadcasting\RecordingBroadcaster;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-03) — Filament side of the shared Reverb server:
 * permission-targeted bell alerts (live on admin.{id}), the live driver map
 * with polling fallback, the operations listener and marketing messages.
 */
class FilamentRealtimeTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function admin(array $permissions): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        return $admin;
    }

    protected function enableEcho(): void
    {
        config(['filament.broadcasting.echo' => ['broadcaster' => 'reverb', 'key' => 'public-test-key', 'wsHost' => 'localhost', 'wsPort' => 8080, 'wssPort' => 8080, 'forceTLS' => false, 'enabledTransports' => ['ws', 'wss'], 'authEndpoint' => '/broadcasting/auth']]);
    }

    public function test_operational_alerts_reach_only_admins_who_can_act_live_in_the_bell(): void
    {
        $resolver = $this->admin(['orders.view', 'orders.resolve_cancellation_requests']);
        $viewer = $this->admin(['orders.view']);
        $plus = $this->admin(['plus.view']);

        $user = $this->demoCustomer(3);
        $order = Order::where('user_id', $user->id)->whereIn('status', ['accepted', 'assigned', 'picked_up'])->firstOrFail();
        $this->api($this->loginCustomer($user))->postJson("/api/v1/customer/orders/{$order->id}/cancellation-request", ['note' => 'please'])->assertOk();

        $alert = $resolver->notifications()->latest()->first();
        $this->assertNotNull($alert);
        $this->assertSame('filament', $alert->data['format']);
        $this->assertStringContainsString($order->order_number, $alert->data['body']);
        $this->assertSame(0, $viewer->notifications()->count(), 'orders.view alone cannot resolve cancellation requests.');
        $this->assertSame(0, $plus->notifications()->count());
        $this->assertCount(1, RecordingBroadcaster::on("private-admin.{$resolver->id}", 'database-notifications.sent'));
        $this->assertSame([], RecordingBroadcaster::on("private-admin.{$viewer->id}"));

        // Driver problem reports alert every orders.view admin.
        $driver = DemoPeopleSeeder::driver(1);
        $driver->forceFill(['password' => 'test-only-password-1'])->save();
        $token = $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->json('data.token');
        $delivery = Order::where('order_number', 'DEMO-0031')->firstOrFail();
        $this->api($token)->postJson("/api/v1/driver/orders/{$delivery->id}/problems", ['type' => 'wrong_address', 'note' => 'gate locked'])->assertOk();
        $this->assertSame(1, $viewer->notifications()->where('type', 'Filament\\Notifications\\DatabaseNotification')->count());

        // And they are listed (filterable by type) in the notifications screen.
        $this->actingAs($this->admin(['notifications.view']), 'admin_web');
        Livewire::test(ListNotifications::class)->filterTable('type', 'driver.problem_reported')->assertCanSeeTableRecords(
            DatabaseNotification::where('type', 'driver.problem_reported')->get()
        );
    }

    public function test_driver_map_is_live_with_reverb_and_falls_back_to_polling(): void
    {
        $tracker = $this->admin(['drivers.track', 'orders.view']);
        $this->actingAs($tracker, 'admin_web');

        $this->get('/admin/driver-map')->assertOk()->assertSee('driver-map-mode', false)
            ->assertSee(__('admin.driver_map.polling', ['seconds' => max(5, (int) config('waffrli.driver_map.refresh_seconds', 30))]))
            ->assertDontSee('waffrliOperationsListener', false);

        $this->enableEcho();
        $page = $this->get('/admin/driver-map')->assertOk();
        $page->assertSee(__('admin.driver_map.live'))->assertSee("private('admin.drivers-map')", false)->assertSee('waffrliOperationsListener', false);
        $page->assertDontSee(config('broadcasting.connections.recording.secret'), false);

        // A finished delivery disappears on the next (live-triggered) refresh.
        $order = Order::where('order_number', 'DEMO-0031')->firstOrFail();
        $component = Livewire::test(DriverMapPage::class);
        $this->assertNotNull(collect($component->instance()->markers())->firstWhere('id', $order->driver_id));
        $order->forceFill(['status' => 'delivered'])->save();
        $component->call('refreshMap')->assertDispatched('driver-map-updated');
        $this->assertNull(collect($component->instance()->markers())->firstWhere('id', $order->driver_id));

        // Without drivers.track: no page, no channel.
        $this->actingAs($this->admin(['orders.view']), 'admin_web');
        $this->get('/admin/driver-map')->assertForbidden();
    }

    public function test_operations_listener_requires_orders_view(): void
    {
        $this->enableEcho();
        $this->actingAs($this->admin(['customers.view']), 'admin_web');
        $this->get('/admin')->assertOk()->assertDontSee('waffrliOperationsListener', false);
    }

    public function test_marketing_messages_are_permission_gated_and_audited(): void
    {
        $this->actingAs($this->admin(['notifications.view']), 'admin_web');
        Livewire::test(ListMarketingBroadcasts::class)->assertOk()->assertActionHidden(TestAction::make('send'));

        $sender = $this->admin(['notifications.marketing']);
        $this->actingAs($sender, 'admin_web');
        Livewire::test(ListMarketingBroadcasts::class)
            ->callAction(TestAction::make('send'), ['title_en' => 'Hello', 'title_ar' => 'مرحبا', 'body_en' => 'New stores this week', 'body_ar' => 'متاجر جديدة', 'audience' => 'all_customers'])
            ->assertHasNoFormErrors();

        $broadcast = MarketingBroadcast::sole();
        $this->assertSame(MarketingBroadcast::STATUS_SENT, $broadcast->status);
        $this->assertSame($sender->id, $broadcast->admin_id);
        $active = User::query()->whereNull('anonymized_at')->where('is_active', true)->where('account_status', '!=', User::ACCOUNT_SUSPENDED)->count();
        $this->assertSame($active, $broadcast->recipients_count);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'notifications.marketing_queued']);

        $this->actingAs($this->admin([]), 'admin_web');
        $this->get('/admin/marketing-messages')->assertForbidden();
    }
}
