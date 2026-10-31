<?php

namespace Tests\Feature\Push;

use App\Contracts\Push\PushTransport;
use App\Exceptions\BusinessRuleException;
use App\Jobs\Push\SendPushMessage;
use App\Models\Admin;
use App\Models\City;
use App\Models\DeviceToken;
use App\Models\MarketingBroadcast;
use App\Models\Order;
use App\Models\PlusRequest;
use App\Models\User;
use App\Services\Notifications\MarketingBroadcastService;
use App\Services\Order\OrderAdminService;
use App\Services\Plus\PlusService;
use App\Services\Push\ChannelPresence;
use App\Services\Push\DeviceTokenService;
use App\Services\Push\FakePushTransport;
use App\Services\Push\FcmHttpV1Transport;
use App\Support\Broadcasting\RecordingBroadcaster;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-03) — device tokens, stored + live + push notifications,
 * marketing broadcast and the FCM HTTP v1 transport. Only the FAKE push
 * transport and Http::fake are used: nothing is sent to Firebase.
 */
class PushNotificationsTest extends CustomerTestCase
{
    protected function token(string $suffix): string
    {
        return 'fcm-test-token-'.str_repeat('x', 20).'-'.$suffix;
    }

    protected function register(string $bearer, string $token, string $device = 'device-1', string $base = 'customer')
    {
        return $this->api($bearer)->postJson("/api/v1/{$base}/device-tokens", ['token' => $token, 'platform' => 'android', 'device_id' => $device, 'device_name' => 'Pixel']);
    }

    protected function admin(array $permissions): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $permissions === ['*'] ? $admin->assignRole(Admin::SUPER_ADMIN_ROLE) : $admin->givePermissionTo($permissions);

        return $admin;
    }

    public function test_device_tokens_are_owned_validated_and_one_device_for_customers(): void
    {
        $alice = $this->demoCustomer(1);
        $bob = $this->demoCustomer(2);
        $a = $this->loginCustomer($alice);
        $b = $this->loginCustomer($bob);

        $first = $this->register($a, $this->token('a1'))->assertOk()->json('data.device_token');
        $this->assertStringNotContainsString($this->token('a1'), json_encode($first), 'Full token is never echoed.');
        $second = $this->register($a, $this->token('a2'), 'device-2')->assertOk()->json('data.device_token');
        $tokens = $this->api($a)->getJson('/api/v1/customer/device-tokens')->assertOk()->json('data.device_tokens');
        $this->assertSame([$second['id']], array_column(array_filter($tokens, fn ($t) => $t['is_active']), 'id'), 'Customers keep one active device.');

        // Ownership: Bob cannot update or remove Alice's device.
        $this->api($b)->patchJson("/api/v1/customer/device-tokens/{$second['id']}", ['token' => $this->token('b-steal')])->assertStatus(404);
        $this->api($b)->deleteJson("/api/v1/customer/device-tokens/{$second['id']}")->assertStatus(404);

        // Rotation and removal by the owner.
        $this->api($a)->patchJson("/api/v1/customer/device-tokens/{$second['id']}", ['token' => $this->token('a2-rotated')])->assertOk();
        $this->assertSame($this->token('a2-rotated'), DeviceToken::find($second['id'])->token);
        $this->api($a)->deleteJson("/api/v1/customer/device-tokens/{$second['id']}")->assertOk();
        $this->assertNull(DeviceToken::find($second['id']));

        // The same installation signing in with another account moves the token.
        $this->register($a, $this->token('shared'))->assertOk();
        $this->register($b, $this->token('shared'))->assertOk();
        $row = DeviceToken::where('token', $this->token('shared'))->sole();
        $this->assertSame($bob->id, (int) $row->tokenable_id);

        // Validation (BasicRequest envelope: 400).
        $this->api($a)->postJson('/api/v1/customer/device-tokens', ['token' => 'short', 'platform' => 'symbian'])->assertStatus(400);
    }

    public function test_drivers_keep_a_bounded_number_of_devices(): void
    {
        $driver = DemoPeopleSeeder::driver(1);
        $driver->forceFill(['password' => 'test-only-password-1'])->save();
        $bearer = $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->json('data.token');

        foreach (range(1, 4) as $i) {
            $this->travel(1)->minutes();
            $this->register($bearer, $this->token("d{$i}"), "driver-device-{$i}", 'driver')->assertOk();
        }
        $active = DeviceToken::where('tokenable_type', $driver->getMorphClass())->where('tokenable_id', $driver->id)->where('is_active', true)->pluck('token')->all();
        $this->assertEqualsCanonicalizing([$this->token('d2'), $this->token('d3'), $this->token('d4')], $active);
    }

    public function test_order_progress_is_stored_broadcast_and_pushed_without_sensitive_data(): void
    {
        config(['waffrli.push.enabled' => true]);
        $order = Order::where('order_number', 'DEMO-0023')->firstOrFail(); // pending
        $customer = User::findOrFail($order->user_id);
        $bearer = $this->loginCustomer($customer);
        $this->register($bearer, $this->token('c1'))->assertOk();

        app(OrderAdminService::class)->accept($order, $this->admin(['*']));

        $stored = $customer->notifications()->latest()->first();
        $this->assertSame('order.accepted', $stored->type);
        $live = RecordingBroadcaster::on("private-user.{$customer->id}", BroadcastNotificationCreated::class);
        $this->assertCount(1, $live);
        $this->assertSame('notification.created', $live[0]['payload']['type']);
        $pushed = FakePushTransport::to($this->token('c1'));
        $this->assertCount(1, $pushed);
        $this->assertSame(['event' => 'order.accepted', 'notification_id' => $stored->id, 'order_id' => (string) $order->id, 'order_number' => $order->order_number], $pushed[0]['message']['data']);

        $everything = json_encode([$stored->data, $live, $pushed]);
        $address = array_filter(array_map('strval', array_filter((array) $order->delivery_address, 'is_scalar')), fn ($v) => mb_strlen($v) > 6);
        foreach ([...$address, (string) $customer->phone, (string) $order->total] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }

        // API: list, unread count, read, read-all; other customers' ids are 404.
        $list = $this->api($bearer)->getJson('/api/v1/customer/notifications')->assertOk()->json('data');
        $this->assertSame('order.accepted', $list['notifications'][0]['event']);
        $this->assertSame((string) $order->id, (string) $list['notifications'][0]['references']['order_id']);
        $this->api($bearer)->getJson('/api/v1/customer/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', $list['unread_count']);
        $other = $this->loginCustomer($this->demoCustomer(9));
        $this->api($other)->postJson("/api/v1/customer/notifications/{$stored->id}/read")->assertStatus(404);
        $this->api($bearer)->postJson("/api/v1/customer/notifications/{$stored->id}/read")->assertOk();
        $this->api($bearer)->postJson('/api/v1/customer/notifications/read')->assertOk();
        $this->api($bearer)->getJson('/api/v1/customer/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_push_is_skipped_online_deduplicated_retried_and_invalid_tokens_are_dropped(): void
    {
        $user = $this->demoCustomer(1);
        $token = app(DeviceTokenService::class)->register($user, ['token' => $this->token('job'), 'platform' => 'ios', 'device_id' => 'd1']);
        $message = ['title' => 'T', 'body' => 'B', 'data' => ['event' => 'x']];
        $run = fn (SendPushMessage $job) => app()->call([$job, 'handle']);

        ChannelPresence::$occupiedForTesting = ["private-user.{$user->id}"];
        $this->assertSame('skipped_online', $run(new SendPushMessage($token->id, $message, 'k1', "private-user.{$user->id}")));
        ChannelPresence::$occupiedForTesting = [];

        FakePushTransport::$failOnce = [$this->token('job')];
        try {
            $run(new SendPushMessage($token->id, $message, 'k2'));
            $this->fail('A transient failure must throw so the queue retries.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(PushTransport::RESULT_SENT, $run(new SendPushMessage($token->id, $message, 'k2')), 'Retry sends.');
        $this->assertSame('skipped_duplicate', $run(new SendPushMessage($token->id, $message, 'k2')));
        $this->assertCount(1, FakePushTransport::to($this->token('job')));

        FakePushTransport::$invalid = [$this->token('job')];
        $this->assertSame(PushTransport::RESULT_INVALID_TOKEN, $run(new SendPushMessage($token->id, $message, 'k3')));
        $this->assertFalse($token->fresh()->is_active);
        $this->assertSame('skipped_inactive', $run(new SendPushMessage($token->id, $message, 'k4')));
    }

    public function test_fake_transport_is_observable_across_processes_without_exposing_tokens(): void
    {
        $user = $this->demoCustomer(1);
        $service = app(DeviceTokenService::class);
        $message = ['title' => 'Order accepted', 'body' => 'B', 'data' => ['event' => 'x']];
        $run = fn (SendPushMessage $job) => app()->call([$job, 'handle']);

        $dead = $service->register($user, ['token' => FakePushTransport::UNREGISTERED_PREFIX.str_repeat('d', 24), 'platform' => 'android', 'device_id' => 'dead']);
        $this->assertSame(PushTransport::RESULT_INVALID_TOKEN, $run(new SendPushMessage($dead->id, $message, 'u1')));
        $this->assertFalse($dead->fresh()->is_active, 'An unregistered token is deactivated, like an FCM UNREGISTERED answer.');

        Log::spy();
        $live = $service->register($user, ['token' => $this->token('live'), 'platform' => 'android', 'device_id' => 'live']);
        $this->assertSame(PushTransport::RESULT_SENT, $run(new SendPushMessage($live->id, $message, 'u2')));
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $context = []) => $event === 'push.fake_delivered'
            && $context['title'] === 'Order accepted'
            && $context['token_sha256'] === substr(hash('sha256', $this->token('live')), 0, 16)
            && ! str_contains(json_encode($context), $this->token('live')));
    }

    public function test_push_disabled_by_default_and_logout_stops_pushes(): void
    {
        $order = Order::where('order_number', 'DEMO-0023')->firstOrFail();
        $customer = User::findOrFail($order->user_id);
        $bearer = $this->loginCustomer($customer);
        $this->register($bearer, $this->token('off'))->assertOk();
        app(OrderAdminService::class)->accept($order, $this->admin(['*']));
        $this->assertSame([], FakePushTransport::$sent, 'Nothing is pushed unless waffrli.push.enabled.');
        $this->assertSame(1, $customer->notifications()->where('type', 'order.accepted')->count(), 'Still stored.');

        $this->api($bearer)->postJson('/api/v1/customer/auth/logout', ['device_id' => 'device-1'])->assertOk();
        $this->assertFalse(DeviceToken::where('token', $this->token('off'))->value('is_active'));
    }

    public function test_plus_decisions_notify_the_customer(): void
    {
        config(['waffrli.push.enabled' => true]);
        $request = PlusRequest::where('status', PlusRequest::STATUS_PENDING)->firstOrFail();
        app(PlusService::class)->reject($request, $this->admin(['*']), 'Not eligible yet');
        $note = $request->user->notifications()->latest()->first();
        $this->assertSame('plus.request_rejected', $note->type);
        $this->assertStringNotContainsString('Not eligible yet', json_encode($note->data), 'Admin notes stay in the API.');
    }

    public function test_marketing_broadcast_requires_permission_and_targets_active_customers(): void
    {
        config(['waffrli.push.enabled' => true]);
        $data = ['title' => ['ar' => 'عطلة', 'en' => 'Weekend'], 'body' => ['ar' => 'توصيل مجاني', 'en' => 'Free delivery all weekend'], 'audience' => 'city', 'city_id' => City::where('name->en', 'Aleppo')->value('id')];

        try {
            app(MarketingBroadcastService::class)->create($this->admin(['orders.view']), $data);
            $this->fail('Marketing needs notifications.marketing.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }

        $aleppo = $this->demoCustomer(9);
        $suspended = User::whereHas('addresses', fn ($q) => $q->where('is_default', true)->where('city_id', $data['city_id']))->where('id', '!=', $aleppo->id)->first();
        $suspended?->forceFill(['account_status' => User::ACCOUNT_SUSPENDED])->save();

        $broadcast = app(MarketingBroadcastService::class)->create($this->admin(['notifications.marketing']), $data);
        $broadcast->refresh();
        $this->assertSame(MarketingBroadcast::STATUS_SENT, $broadcast->status);
        $this->assertSame(1, $aleppo->notifications()->where('type', 'marketing.message')->count());
        $this->assertSame(0, $this->demoCustomer(1)->notifications()->where('type', 'marketing.message')->count(), 'Other cities are not targeted.');
        if ($suspended) {
            $this->assertSame(0, $suspended->notifications()->where('type', 'marketing.message')->count());
        }
        $this->assertSame(0, app(MarketingBroadcastService::class)->deliver($broadcast), 'Never sent twice.');
    }

    public function test_fcm_http_v1_transport_signs_and_maps_answers(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['waffrli.push.fcm' => ['credentials' => null, 'project_id' => 'demo-project', 'client_email' => 'push@demo-project.iam.gserviceaccount.com', 'private_key' => $pem, 'timeout' => 5]]);

        $mode = 'ok';
        Http::fake(function (HttpRequest $request) use (&$mode) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]);
            }

            return match ($mode) {
                'ok' => Http::response(['name' => 'projects/demo-project/messages/1']),
                'gone' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
                default => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
            };
        });

        $transport = new FcmHttpV1Transport;
        $message = ['title' => 'T', 'body' => 'B', 'data' => ['order_id' => '5']];
        $this->assertSame(PushTransport::RESULT_SENT, $transport->send('device-token-1', $message));
        $mode = 'gone';
        $this->assertSame(PushTransport::RESULT_INVALID_TOKEN, $transport->send('device-token-1', $message));
        $mode = 'down';
        $this->expectException(\RuntimeException::class);
        try {
            $transport->send('device-token-1', $message);
        } finally {
            Http::assertSent(function (HttpRequest $request) {
                if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/demo-project/messages:send')) {
                    return false;
                }

                return $request->header('Authorization')[0] === 'Bearer test-access-token' && $request['message']['token'] === 'device-token-1'
                    && $request['message']['data'] === ['order_id' => '5'];
            });
            Http::assertSent(function (HttpRequest $request) {
                if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                    return false;
                }
                [$h, $p] = explode('.', $request['assertion']);
                $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

                return $claims['scope'] === FcmHttpV1Transport::SCOPE && $claims['iss'] === 'push@demo-project.iam.gserviceaccount.com';
            });
        }
    }
}
