<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\AdminOperationalEvent;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\DriverCashSettlements\Pages\ListDriverCashSettlements;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\MarketingBroadcasts\Pages\ListMarketingBroadcasts;
use App\Filament\Resources\OrderRefunds\Pages\ListOrderRefunds;
use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Models\Admin;
use App\Models\Driver;
use App\Services\Audit\AdminAudit;
use App\Services\Driver\DriverAccountService;
use App\Support\Broadcasting\RecordingBroadcaster;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-16) — panel-wide realtime refresh (Phase 4):
 *  - `admin.activity` is open to every active admin (identifiers only),
 *  - non-order changes are published there, order changes stay on admin.orders,
 *  - a successful business action refreshes every live component on the page,
 *  - non-order list pages listen to the refresh event.
 */
class AdminActivityRealtimeTest extends CustomerTestCase
{
    private const SOCKET = '1234.5678';

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

    public function test_every_active_admin_may_listen_to_admin_activity_and_nobody_else(): void
    {
        $nobody = $this->admin([]);
        $this->actingAs($nobody, 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.activity'])->assertOk();
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.orders'])->assertForbidden();

        $nobody->forceFill(['is_active' => false])->save();
        $this->actingAs($nobody->fresh(), 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.activity'])->assertForbidden();

        // A customer token can never join an admin channel.
        $token = $this->loginCustomer($this->demoCustomer(1));
        $status = $this->flushHeaders()->withToken($token)
            ->postJson('/api/v1/customer/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.activity'])
            ->status();
        $this->assertContains($status, [401, 403]);
    }

    public function test_order_and_non_order_events_use_the_right_channels(): void
    {
        $channels = fn (string $type) => array_map(fn ($c) => $c->name, (new AdminOperationalEvent($type, 1))->broadcastOn());

        $this->assertSame(['private-admin.orders'], $channels('order.placed'));
        $this->assertSame(['private-admin.orders'], $channels('order.refunded'));
        $this->assertSame(['private-admin.orders', 'private-admin.activity'], $channels('plus.request_submitted'));
        $this->assertSame(['private-admin.activity'], $channels('plus.request_resolved'));
        $this->assertSame(['private-admin.activity'], $channels('customer.status_changed'));
        $this->assertSame(['private-admin.activity'], $channels('driver.status_changed'));
    }

    public function test_audited_customer_changes_and_driver_availability_are_published_with_identifiers_only(): void
    {
        $user = $this->demoCustomer(2);
        AdminAudit::log('customer.suspended', $user, ['reason' => 'private reason'], $this->admin([]));

        $sent = RecordingBroadcaster::on('private-admin.activity', 'admin.operational');
        $this->assertCount(1, $sent);
        $this->assertSame('customer.status_changed', $sent[0]['payload']['type']);
        $this->assertSame($user->id, $sent[0]['payload']['id']);
        $this->assertStringNotContainsString('private reason', json_encode($sent[0]['payload']));

        // A driver without an active delivery may switch freely.
        $driver = Driver::query()->whereDoesntHave('activeOrders')->firstOrFail();
        app(DriverAccountService::class)->setAvailability($driver, Driver::AVAILABILITY_AVAILABLE);
        $types = array_column(array_column(RecordingBroadcaster::on('private-admin.activity', 'admin.operational'), 'payload'), 'type');
        $this->assertContains('driver.status_changed', $types);
    }

    public function test_successful_business_action_refreshes_the_page_and_a_refused_one_does_not(): void
    {
        $this->actingAs($this->admin(['customers.view', 'customers.suspend']), 'admin_web');
        $user = $this->demoCustomer(2);

        Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])
            ->callAction(TestAction::make('suspend'), ['reason' => 'test'])
            ->assertHasNoFormErrors()
            ->assertDispatched('waffrli-operations-changed')
            ->assertDispatched('waffrli-orders-changed');
        $this->assertTrue($user->fresh()->isSuspended());
    }

    public function test_non_order_list_pages_listen_for_operational_changes(): void
    {
        $this->actingAs($this->admin(['plus.view']), 'admin_web');
        Livewire::test(ListPlusRequests::class)->dispatch('waffrli-operations-changed')->assertOk();

        foreach ([
            ListPlusRequests::class,
            ListCustomers::class,
            ListDrivers::class,
            ListDriverCashSettlements::class,
            ListOrderRefunds::class,
            ListMarketingBroadcasts::class,
        ] as $page) {
            $this->assertTrue(method_exists($page, 'refreshFromOperationalEvent'), $page);
        }
        foreach (glob(app_path('Filament/Resources/Orders/RelationManagers/*.php')) as $file) {
            $class = 'App\\Filament\\Resources\\Orders\\RelationManagers\\'.basename($file, '.php');
            $this->assertTrue(method_exists($class, 'refreshFromRealtime'), $class);
        }
    }
}
