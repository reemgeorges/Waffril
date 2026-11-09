<?php

namespace Tests\Feature\Delivery;

use App\Contracts\Push\PushTransport;
use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Jobs\Push\SendPushMessage;
use App\Models\Admin;
use App\Models\City;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Notifications\DriverDeliveryOfferCreated;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Order\OrderAdminService;
use App\Services\Push\ChannelPresence;
use App\Services\Push\DeviceTokenService;
use App\Services\Push\FakePushTransport;
use App\Services\Push\FcmHttpV1Transport;
use App\Support\Broadcasting\RecordingBroadcaster;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — driver offer API, Reverb offer.*
 * events and the FCM offer push. Only the recording broadcaster, the fake
 * push transport and Http::fake are used: nothing leaves the test.
 */
class DriverOfferApiTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected Admin $admin;

    protected City $cityModel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dispatch.offers_enabled' => true]);
        RecordingBroadcaster::reset();
        FakePushTransport::reset();
        ChannelPresence::$occupiedForTesting = [];
        $this->admin = $this->superAdmin();
        $this->cityModel = $this->city();
    }

    protected function acceptedOrder(): Order
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->cityModel), '10', ['cash' => '10']);

        return app(OrderAdminService::class)->accept($order, $this->admin)->fresh();
    }

    protected function newDriver(array $attributes = []): Driver
    {
        return $this->driver($this->cityModel, $attributes);
    }

    protected function offer(Order $order, Driver $driver): DeliveryOffer
    {
        return app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->admin);
    }

    protected function tokenFor(Driver $driver): string
    {
        $driver->forceFill(['password' => 'test-only-password-1'])->save();

        return $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->assertOk()->json('data.token');
    }

    protected function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    /** Customer data that must never appear before acceptance. */
    protected function privateValues(Order $order): array
    {
        $order->loadMissing('user');
        $address = array_filter(array_map('strval', array_filter((array) $order->delivery_address, 'is_scalar')), fn ($v) => mb_strlen($v) > 5);

        $values = array_values(array_filter([...$address, (string) $order->user->phone, (string) $order->user->fullName(), (string) $order->customer_notes]));
        // Guard: the privacy assertions must check real values (phone, name and an address line).
        $this->assertGreaterThanOrEqual(3, count($values), 'The fixture order must carry customer data to hide.');

        return $values;
    }

    public function test_current_offer_is_privacy_limited_and_timed_by_the_server(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $token = $this->tokenFor($driver);

        $this->api($token)->getJson('/api/v1/driver/offers/current')->assertOk()->assertJsonPath('data.offer', null);

        $offer = $this->offer($order, $driver);
        $response = $this->api($token)->getJson('/api/v1/driver/offers/current')->assertOk();
        $data = $response->json('data.offer');

        $this->assertSame($offer->id, $data['id']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame($order->order_number, $data['order']['order_number']);
        $this->assertSame(1, $data['order']['items_count']);
        $this->assertSame('10.00', $data['payment']['cash_to_collect']);
        $this->assertSame(['accept', 'reject'], $data['allowed_actions']);
        $this->assertEqualsWithDelta(90, $data['remaining_seconds'], 1);
        $this->assertNotNull($data['server_now']);
        $this->assertSame(['id', 'status', 'status_label', 'order', 'store', 'pickup', 'delivery', 'payment', 'offered_at', 'expires_at', 'server_now', 'remaining_seconds', 'responded_at', 'reject_reason', 'allowed_actions'], array_keys($data));
        $this->assertSame(['city', 'area', 'distance_km'], array_keys($data['delivery']), 'No address, coordinates or instructions before acceptance.');
        $this->assertArrayNotHasKey('customer', $data);

        foreach ($this->privateValues($order) as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_offers_are_private_to_the_offered_driver(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $other = $this->newDriver();
        $offer = $this->offer($order, $driver);
        $otherToken = $this->tokenFor($other);

        $this->api($otherToken)->getJson('/api/v1/driver/offers/current')->assertOk()->assertJsonPath('data.offer', null);
        $this->api($otherToken)->getJson("/api/v1/driver/offers/{$offer->id}")->assertStatus(404);
        $this->api($otherToken)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertStatus(404);
        $this->api($otherToken)->postJson("/api/v1/driver/offers/{$offer->id}/reject", ['reason' => 'busy'])->assertStatus(404);
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);

        // No token, or a customer token: refused by the driver guard.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/driver/offers/current', ['Accept' => 'application/json'])->assertStatus(401);
        $customerToken = $order->user->createToken('customer-app', ['customer'])->plainTextToken;
        $this->api($customerToken)->getJson('/api/v1/driver/offers/current')->assertStatus(401);
        $this->api($customerToken)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertStatus(401);
    }

    public function test_accept_assigns_once_and_returns_the_delivery(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->offer($order, $driver);
        $token = $this->tokenFor($driver);

        $response = $this->api($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk();
        $response->assertJsonPath('data.offer.status', 'accepted')
            ->assertJsonPath('data.offer.allowed_actions', [])
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.order.status', 'assigned')
            ->assertJsonPath('data.order.allowed_actions', ['pickup', 'report_problem']);
        // Now the delivery is theirs: customer details come with the order, as before.
        $this->assertNotNull($response->json('data.order.customer.phone'));

        // A retried request (lost response, double tap) is answered the same way, without a second assignment.
        $this->api($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk()->assertJsonPath('data.order.status', 'assigned');
        $this->assertCount(1, OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'assigned')->get());
        $this->api($token)->getJson('/api/v1/driver/offers/current')->assertOk()->assertJsonPath('data.offer', null);
        $this->api($token)->getJson('/api/v1/driver/orders/active')->assertOk()->assertJsonPath('data.orders.0.id', $order->id);
    }

    public function test_reject_validates_records_and_is_final(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->offer($order, $driver);
        $token = $this->tokenFor($driver);
        $url = "/api/v1/driver/offers/{$offer->id}";

        $this->api($token)->postJson("{$url}/reject", ['reason' => 'tired'])->assertStatus(400);
        $this->api($token)->postJson("{$url}/reject", ['reason' => 'other'])->assertStatus(400); // note required
        $this->api($token)->postJson("{$url}/reject", [])->assertStatus(400);
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);

        $this->api($token)->postJson("{$url}/reject", ['reason' => 'too_far', 'note' => 'other side of town'])->assertOk()
            ->assertJsonPath('data.offer.status', 'rejected')->assertJsonPath('data.offer.reject_reason', 'too_far');
        $this->assertSame('other side of town', $offer->fresh()->reject_note);
        // Retried reject: same answer. A rejected offer cannot be accepted.
        $this->api($token)->postJson("{$url}/reject", ['reason' => 'too_far'])->assertOk();
        $this->api($token)->postJson("{$url}/accept")->assertStatus(409);
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        // The driver can still look at their past offer.
        $this->api($token)->getJson($url)->assertOk()->assertJsonPath('data.offer.status', 'rejected');
    }

    public function test_expired_and_withdrawn_offers_cannot_be_accepted(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = $this->offer($order, $driver);
        $token = $this->tokenFor($driver);

        $this->travel(90)->seconds();
        // Reading the current offer after the deadline expires it.
        $this->api($token)->getJson('/api/v1/driver/offers/current')->assertOk()->assertJsonPath('data.offer', null);
        $this->assertSame(DeliveryOfferStatus::Expired, $offer->fresh()->status);
        $this->api($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertStatus(409);

        // An offer withdrawn by a direct admin assignment cannot be accepted either.
        $second = $this->offer($order, $this->newDriver());
        $chosen = $this->newDriver();
        app(OrderAdminService::class)->assignDriver($order, $chosen, $this->admin);
        $secondToken = $this->tokenFor($second->driver);
        $this->api($secondToken)->postJson("/api/v1/driver/offers/{$second->id}/accept")->assertStatus(409);
        $this->assertSame($chosen->id, (int) $order->fresh()->driver_id);
    }

    public function test_offer_events_reach_only_the_offered_driver_after_commit(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $other = $this->newDriver();
        $offer = $this->offer($order, $driver);
        $channel = "private-driver.{$driver->id}";

        $created = RecordingBroadcaster::on($channel, 'offer.created');
        $this->assertCount(1, $created);
        $payload = $created[0]['payload'];
        $this->assertSame(['event', 'offer_id', 'order_id', 'status', 'reason', 'at', 'offer'], array_keys($payload));
        $this->assertSame([$offer->id, $order->id, 'pending'], [$payload['offer_id'], $payload['order_id'], $payload['status']]);
        // Same shape as GET /driver/offers/current.
        $rest = $this->api($this->tokenFor($driver))->getJson('/api/v1/driver/offers/current')->json('data.offer');
        $this->assertSame(array_keys($rest), array_keys($payload['offer']));
        $this->assertSame($rest['expires_at'], $payload['offer']['expires_at']);
        foreach ($this->privateValues($order) as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($created));
        }
        $this->assertSame([], RecordingBroadcaster::on("private-driver.{$other->id}"));

        // A failed transaction announces nothing.
        try {
            app(DeliveryOfferService::class)->offerByAdmin($order, $other, $this->admin); // already pending: refused
        } catch (\Throwable) {
        }
        $this->assertSame([], RecordingBroadcaster::on("private-driver.{$other->id}"));

        app(DeliveryOfferService::class)->accept($offer, $driver);
        $accepted = RecordingBroadcaster::on($channel, 'offer.accepted');
        $this->assertCount(1, $accepted);
        $this->assertSame('accepted', $accepted[0]['payload']['status']);
        $this->assertNotEmpty(RecordingBroadcaster::on("private-order.{$order->id}", 'order.status_changed'), 'The existing order event still fires.');
    }

    public function test_withdrawn_events_for_reject_expiry_and_cancellation(): void
    {
        $service = app(DeliveryOfferService::class);
        $order = $this->acceptedOrder();
        [$a, $b, $c] = [$this->newDriver(), $this->newDriver(), $this->newDriver()];

        $service->reject($this->offer($order, $a), $a, 'busy');
        $expiring = $service->offerAutomatically($order, $b);
        $this->travel(91)->seconds();
        $service->expire($expiring);
        $this->travelBack();
        $service->offerAutomatically($order, $c);
        app(OrderAdminService::class)->cancelByAdmin($order, $this->admin, ['reason_code' => 'other', 'note' => 'test']);

        foreach ([[$a, 'rejected', 'busy'], [$b, 'expired', null], [$c, 'cancelled', 'order_cancelled']] as [$driver, $status, $reason]) {
            $events = RecordingBroadcaster::on("private-driver.{$driver->id}", 'offer.withdrawn');
            $this->assertCount(1, $events, "One withdrawal for {$status}.");
            $this->assertSame([$status, $reason], [$events[0]['payload']['status'], $events[0]['payload']['reason']]);
        }
    }

    public function test_driver_channel_authorization_is_unchanged(): void
    {
        $driver = $this->newDriver();
        $other = $this->newDriver();
        $token = $this->tokenFor($driver);
        $auth = fn (string $channel) => $this->api($token)->postJson('/api/v1/driver/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $auth("private-driver.{$driver->id}")->assertOk();
        $auth("private-driver.{$other->id}")->assertForbidden();
    }

    public function test_a_new_offer_is_pushed_once_to_every_device_without_customer_data(): void
    {
        config(['waffrli.push.enabled' => true]);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $tokens = app(DeviceTokenService::class);
        foreach (['phone', 'tablet'] as $device) {
            $tokens->register($driver, ['token' => "fcm-offer-test-token-{$device}-".str_repeat('x', 20), 'platform' => 'android', 'device_id' => $device]);
        }

        $offer = $this->offer($order, $driver);

        foreach (['phone', 'tablet'] as $device) {
            $pushed = FakePushTransport::to("fcm-offer-test-token-{$device}-".str_repeat('x', 20));
            $this->assertCount(1, $pushed, "One push for {$device}.");
            $data = $pushed[0]['message']['data'];
            $this->assertSame('driver.offer_created', $data['event']);
            $this->assertSame((string) $offer->id, $data['offer_id']);
            $this->assertSame($offer->expires_at->toIso8601String(), $data['expires_at']);
            $this->assertSame($offer->expires_at->toIso8601String(), $pushed[0]['message']['expires_at']);
            foreach ($this->privateValues($order) as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($pushed));
            }
        }
        $this->assertSame(0, $driver->notifications()->count(), 'Offers are not stored in the notification list.');

        // The same offer notified again (a retried dispatch) is not pushed twice.
        $driver->notify(new DriverDeliveryOfferCreated($offer, $order));
        $this->assertCount(1, FakePushTransport::to('fcm-offer-test-token-phone-'.str_repeat('x', 20)));
    }

    public function test_push_failures_and_late_retries_never_touch_the_offer(): void
    {
        config(['waffrli.push.enabled' => true]);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $device = 'fcm-offer-test-token-flaky-'.str_repeat('x', 20);
        $token = app(DeviceTokenService::class)->register($driver, ['token' => $device, 'platform' => 'android', 'device_id' => 'flaky']);
        FakePushTransport::$failOnce = [$device];

        $offer = $this->offer($order, $driver);
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status, 'A push failure does not undo the offer.');
        $this->assertSame(Order::DISPATCH_OFFERING, $order->fresh()->dispatch_state);

        // A queued retry after the deadline is dropped instead of pushing a stale offer.
        $message = (new DriverDeliveryOfferCreated($offer, $order))->toPush($driver);
        $run = fn (SendPushMessage $job) => app()->call([$job, 'handle']);
        $this->travel(91)->seconds();
        $this->assertSame('skipped_expired', $run(new SendPushMessage($token->id, $message, 'retry-after-deadline')));
        $this->travelBack();
        // Before the deadline the retry goes out; an online driver is not pushed at all.
        $this->assertSame(PushTransport::RESULT_SENT, $run(new SendPushMessage($token->id, $message, 'retry-in-time')));
        ChannelPresence::$occupiedForTesting = ["private-driver.{$driver->id}"];
        $this->assertSame('skipped_online', $run(new SendPushMessage($token->id, $message, 'online', "private-driver.{$driver->id}")));
    }

    public function test_fcm_message_carries_a_ttl_for_time_limited_offers(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('NOT RUN: PHP OpenSSL cannot generate a key here (set OPENSSL_CONF).');
        }
        openssl_pkey_export($key, $pem);
        config(['waffrli.push.fcm' => ['credentials' => null, 'project_id' => 'demo-project', 'client_email' => 'push@demo-project.iam.gserviceaccount.com', 'private_key' => $pem, 'timeout' => 5]]);
        Http::fake(fn (HttpRequest $request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
            ? Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600])
            : Http::response(['name' => 'projects/demo-project/messages/1']));

        $expiresAt = now()->addSeconds(60)->toIso8601String();
        (new FcmHttpV1Transport)->send('device-token-1', ['title' => 'T', 'body' => 'B', 'data' => ['offer_id' => '5'], 'expires_at' => $expiresAt]);
        (new FcmHttpV1Transport)->send('device-token-2', ['title' => 'T', 'body' => 'B', 'data' => ['order_id' => '5']]);

        Http::assertSent(fn (HttpRequest $r) => ($r['message']['token'] ?? null) === 'device-token-1'
            && $r['message']['android']['priority'] === 'HIGH'
            && in_array($r['message']['android']['ttl'], ['59s', '60s'], true)
            && $r['message']['apns']['headers']['apns-expiration'] === (string) now()->addSeconds(60)->getTimestamp()
            && $r['message']['data'] === ['offer_id' => '5']);
        // Messages without a deadline are unchanged.
        Http::assertSent(fn (HttpRequest $r) => ($r['message']['token'] ?? null) === 'device-token-2'
            && $r['message']['android'] === ['priority' => 'HIGH']
            && $r['message']['apns']['headers'] === ['apns-priority' => '10']);
    }

    public function test_existing_direct_assignment_and_disabled_flag_are_unchanged(): void
    {
        config(['dispatch.offers_enabled' => false]);
        Notification::fake();
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $token = $this->tokenFor($driver);

        $this->api($token)->getJson('/api/v1/driver/offers/current')->assertOk()->assertJsonPath('data.offer', null);
        app(OrderAdminService::class)->assignDriver($order, $driver, $this->admin);
        $this->assertSame(OrderStatus::Assigned, $order->fresh()->status);
        $this->assertNull($order->fresh()->assigned_via_offer_id);
        $this->assertSame(0, DeliveryOffer::count());
        $this->assertSame([], RecordingBroadcaster::on("private-driver.{$driver->id}", 'offer.created'));
        Notification::assertNotSentTo($driver, DriverDeliveryOfferCreated::class);
        $this->api($token)->getJson('/api/v1/driver/orders/active')->assertOk()->assertJsonPath('data.orders.0.id', $order->id);
    }

    public function test_reassigning_after_an_accepted_offer_clears_the_offer_link(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        app(DeliveryOfferService::class)->accept($this->offer($order, $driver), $driver);
        $this->assertNotNull($order->fresh()->assigned_via_offer_id);

        app(OrderAdminService::class)->assignDriver($order, $this->newDriver(), $this->admin);
        $this->assertNull($order->fresh()->assigned_via_offer_id);
        app(OrderAdminService::class)->unassignDriver($order, $this->admin);
        $this->assertNull($order->fresh()->assigned_via_offer_id);
    }
}
