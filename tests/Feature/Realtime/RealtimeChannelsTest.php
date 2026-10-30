<?php

namespace Tests\Feature\Realtime;

use App\Models\Admin;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderAdminService;
use App\Support\Broadcasting\RecordingBroadcaster;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-03) — the ONE Reverb server: channel authorization for
 * customer tokens, driver tokens and the Filament admin session, and what
 * the server publishes (status changes, GPS with send-time re-checks,
 * operations alerts). Uses the `recording` broadcaster (no network).
 */
class RealtimeChannelsTest extends CustomerTestCase
{
    private const SOCKET = '1234.5678';

    protected function driverToken(int $n): array
    {
        $driver = DemoPeopleSeeder::driver($n);
        $driver->forceFill(['password' => 'test-only-password-1'])->save();
        $token = $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->assertOk()->json('data.token');

        return [$driver->fresh(), $token];
    }

    protected function authorizeChannel(string $url, ?string $token, string $channel)
    {
        $request = $token ? $this->api($token) : $this;

        return $request->postJson($url, ['socket_id' => self::SOCKET, 'channel_name' => $channel]);
    }

    protected function expectedSignature(string $channel): string
    {
        $key = config('broadcasting.connections.recording.key');

        return $key.':'.hash_hmac('sha256', self::SOCKET.':'.$channel, config('broadcasting.connections.recording.secret'));
    }

    public function test_customer_token_authorizes_only_own_user_and_open_order_channels(): void
    {
        $order = Order::where('order_number', 'DEMO-0031')->firstOrFail(); // assigned
        $owner = User::findOrFail($order->user_id);
        $other = User::where('id', '!=', $owner->id)->whereNull('deleted_at')->firstOrFail();
        $token = $this->loginCustomer($owner);
        $url = '/api/v1/customer/broadcasting/auth';

        $this->authorizeChannel($url, $token, "private-user.{$owner->id}")->assertOk()
            ->assertExactJson(['auth' => $this->expectedSignature("private-user.{$owner->id}")]);
        $this->authorizeChannel($url, $token, "private-order.{$order->id}")->assertOk();

        $this->authorizeChannel($url, $token, "private-user.{$other->id}")->assertForbidden();
        $foreign = Order::where('user_id', '!=', $owner->id)->firstOrFail();
        $this->authorizeChannel($url, $token, "private-order.{$foreign->id}")->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-order.999999')->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-driver.'.$order->driver_id)->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-admin.orders')->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-admin.1')->assertForbidden();
        // Public channels / double prefixes / missing socket are never signed.
        $this->authorizeChannel($url, $token, "user.{$owner->id}")->assertStatus(422);
        $this->authorizeChannel($url, $token, "private-private-user.{$owner->id}")->assertForbidden();
        $this->api($token)->postJson($url, ['channel_name' => "private-user.{$owner->id}"])->assertStatus(422);

        // Finished orders close the order channel.
        $delivered = Order::where('user_id', $owner->id)->where('status', 'delivered')->first();
        if ($delivered) {
            $this->authorizeChannel($url, $token, "private-order.{$delivered->id}")->assertForbidden();
        }

        // Suspension revokes access (the API middleware refuses the token).
        $owner->forceFill(['account_status' => User::ACCOUNT_SUSPENDED])->save();
        $this->authorizeChannel($url, $token, "private-user.{$owner->id}")->assertForbidden();
    }

    public function test_driver_token_authorizes_own_channel_and_current_deliveries_only(): void
    {
        [$driver, $token] = $this->driverToken(1);
        $mine = Order::where('order_number', 'DEMO-0031')->firstOrFail();
        $theirs = Order::where('order_number', 'DEMO-0033')->firstOrFail();
        $url = '/api/v1/driver/broadcasting/auth';

        $this->authorizeChannel($url, $token, "private-driver.{$driver->id}")->assertOk()
            ->assertExactJson(['auth' => $this->expectedSignature("private-driver.{$driver->id}")]);
        $this->authorizeChannel($url, $token, "private-order.{$mine->id}")->assertOk();
        $this->authorizeChannel($url, $token, "private-order.{$theirs->id}")->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-driver.'.$theirs->driver_id)->assertForbidden();
        $this->authorizeChannel($url, $token, "private-user.{$mine->user_id}")->assertForbidden();
        $this->authorizeChannel($url, $token, 'private-admin.drivers-map')->assertForbidden();

        // Reassignment revokes the old driver's order channel.
        app(OrderAdminService::class)->unassignDriver($mine, $this->superAdmin());
        $this->authorizeChannel($url, $token, "private-order.{$mine->id}")->assertForbidden();

        // Tokens never cross identities.
        $customerToken = $this->loginCustomer(User::findOrFail($mine->user_id));
        $this->authorizeChannel($url, $customerToken, "private-driver.{$driver->id}")->assertStatus(401);
        $this->authorizeChannel('/api/v1/customer/broadcasting/auth', $token, "private-driver.{$driver->id}")->assertStatus(401);
    }

    public function test_admin_session_channels_follow_permissions(): void
    {
        $tracker = $this->admin(['orders.view', 'drivers.track']);
        $viewer = $this->admin(['orders.view']);
        $nobody = $this->admin([]);

        $this->actingAs($tracker, 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.drivers-map'])->assertOk()
            ->assertJsonPath('auth', $this->expectedSignature('private-admin.drivers-map'));
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.orders'])->assertOk();
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => "private-admin.{$tracker->id}"])->assertOk();
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => "private-admin.{$viewer->id}"])->assertForbidden();
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-user.1'])->assertForbidden();

        $this->actingAs($viewer, 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.drivers-map'])->assertForbidden();
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.orders'])->assertOk();

        $this->actingAs($nobody, 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.orders'])->assertForbidden();

        // A deactivated admin loses every channel.
        $tracker->forceFill(['is_active' => false])->save();
        $this->actingAs($tracker->fresh(), 'admin_web');
        $this->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.drivers-map'])->assertForbidden();
    }

    public function test_session_endpoint_rejects_api_tokens_and_guests(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        $this->authorizeChannel('/broadcasting/auth', $token, 'private-admin.orders')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/broadcasting/auth', ['socket_id' => self::SOCKET, 'channel_name' => 'private-admin.orders'])->assertStatus(401);
    }

    public function test_gps_reaches_only_current_deliveries_and_the_admin_map(): void
    {
        [$driver, $token] = $this->driverToken(1);
        $order = Order::where('order_number', 'DEMO-0031')->firstOrFail();

        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.51, 'lng' => 36.29])->assertOk();
        $sent = RecordingBroadcaster::on("private-order.{$order->id}", 'driver.location');
        $this->assertCount(1, $sent);
        $this->assertSame(['driver_id' => $driver->id, 'lat' => 33.51, 'lng' => 36.29], array_intersect_key($sent[0]['payload'], array_flip(['driver_id', 'lat', 'lng'])));
        $this->assertCount(1, RecordingBroadcaster::on('private-admin.drivers-map', 'driver.location'));
        $this->assertSame([], RecordingBroadcaster::on("private-user.{$order->user_id}", 'driver.location'), 'GPS only on the order channel.');

        // Status change announced to customer, driver(s) and operations; the
        // previous driver learns it lost the delivery.
        RecordingBroadcaster::reset();
        app(OrderAdminService::class)->unassignDriver($order, $this->superAdmin());
        foreach (["private-order.{$order->id}", "private-user.{$order->user_id}", 'private-admin.orders', "private-driver.{$driver->id}"] as $channel) {
            $this->assertCount(1, RecordingBroadcaster::on($channel, 'order.status_changed'), $channel);
        }
        $payload = RecordingBroadcaster::on("private-order.{$order->id}", 'order.status_changed')[0]['payload'];
        $this->assertSame('accepted', $payload['status']);
        $this->assertFalse($payload['tracking']);
        $this->assertArrayNotHasKey('delivery_address', $payload);

        // No more active delivery: no GPS is accepted or published.
        RecordingBroadcaster::reset();
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.52, 'lng' => 36.3])->assertStatus(409);
        $this->assertSame([], RecordingBroadcaster::$sent);
    }

    public function test_new_orders_and_cancellation_requests_alert_operations(): void
    {
        $user = $this->demoCustomer(3);
        $token = $this->loginCustomer($user);
        $order = Order::where('user_id', $user->id)->whereIn('status', ['accepted', 'assigned', 'picked_up'])->first();
        $this->assertNotNull($order, 'Demo customer 3 has an accepted/active order.');

        $this->api($token)->postJson("/api/v1/customer/orders/{$order->id}/cancellation-request", ['note' => 'changed my mind'])->assertOk();
        $alerts = RecordingBroadcaster::on('private-admin.orders', 'admin.operational');
        $this->assertSame('order.cancellation_requested', $alerts[0]['payload']['type']);
        $this->assertSame($order->order_number, $alerts[0]['payload']['reference']);
        $this->assertStringNotContainsString('changed my mind', json_encode(RecordingBroadcaster::$sent), 'Notes stay in the API.');
    }

    public function test_without_a_pusher_protocol_broadcaster_nothing_is_signed(): void
    {
        $user = $this->demoCustomer(1);
        $token = $this->loginCustomer($user);
        config(['broadcasting.default' => 'log']);
        $this->authorizeChannel('/api/v1/customer/broadcasting/auth', $token, 'private-user.999')->assertStatus(503);
        $this->authorizeChannel('/api/v1/customer/broadcasting/auth', $token, "private-user.{$user->id}")->assertStatus(503);
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    protected function admin(array $permissions): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        return $admin;
    }
}
