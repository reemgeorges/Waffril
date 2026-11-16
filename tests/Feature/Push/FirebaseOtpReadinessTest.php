<?php

namespace Tests\Feature\Push;

use App\Contracts\Messaging\TextMessageGateway;
use App\Contracts\Push\PushTransport;
use App\Jobs\Push\SendPushMessage;
use App\Models\Admin;
use App\Models\Order;
use App\Services\Messaging\FakeTextGateway;
use App\Services\Messaging\TextMessenger;
use App\Services\Messaging\TwilioTextGateway;
use App\Services\Order\OrderAdminService;
use App\Services\Push\DeviceTokenService;
use App\Services\Push\FakePushTransport;
use App\Services\Push\FcmHttpV1Transport;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-16) — readiness of the two delivery paths that do not
 * need a person to look at a phone:
 *  - customer OTP is sent synchronously (TextMessenger inside the request),
 *    so it works with the database queue and no worker running; the code is
 *    never logged; fake and Twilio gateways are separate drivers;
 *  - pushes for customers AND drivers are queued per registered device
 *    through the bound transport (fake here, FCM HTTP v1 when configured).
 * Real Firebase/Twilio delivery is not tested here (no credentials).
 */
class FirebaseOtpReadinessTest extends CustomerTestCase
{
    public function test_otp_is_delivered_synchronously_without_a_queue_worker_and_never_logged(): void
    {
        config(['queue.default' => 'database']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $jobsBefore = DB::table('jobs')->count();
        $phone = $this->demoCustomer(5)->phone;

        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $phone])->assertOk()
            ->assertJsonMissingPath('data.code');

        $code = $this->otpFor($phone);   // already in the (fake) SMS outbox: no worker was needed
        $this->assertSame($jobsBefore, DB::table('jobs')->count(), 'No job is queued for an OTP.');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($code, $line, 'An OTP code must never be logged.');
        }
    }

    public function test_sms_and_push_drivers_are_separate_from_their_fakes(): void
    {
        $this->assertInstanceOf(FakeTextGateway::class, TextMessenger::gatewayFor('fake'));
        $this->assertInstanceOf(TwilioTextGateway::class, TextMessenger::gatewayFor('twilio'));

        config(['waffrli.messaging.driver' => 'twilio']);
        $this->assertInstanceOf(TwilioTextGateway::class, app(TextMessageGateway::class));

        config(['waffrli.push.driver' => 'fake']);
        $this->assertInstanceOf(FakePushTransport::class, app(PushTransport::class));
        config(['waffrli.push.driver' => 'fcm']);
        $this->assertInstanceOf(FcmHttpV1Transport::class, app(PushTransport::class));
    }

    public function test_customer_and_driver_notifications_queue_one_push_per_registered_device(): void
    {
        config(['waffrli.push.enabled' => true, 'waffrli.push.driver' => 'fake', 'waffrli.push.skip_when_online' => false]);
        Queue::fake([SendPushMessage::class]);
        $tokens = app(DeviceTokenService::class);

        $customer = $this->demoCustomer(1);
        $customerToken = $tokens->register($customer, ['token' => 'fcm-cust-'.str_repeat('c', 30), 'platform' => 'android', 'device_id' => 'c1']);
        $driver = DemoPeopleSeeder::driver(5);
        $driverToken = $tokens->register($driver, ['token' => 'fcm-drv-'.str_repeat('d', 30), 'platform' => 'android', 'device_id' => 'd1']);

        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);
        $order = Order::query()->where('user_id', $customer->id)->where('status', 'pending')->first()
            ?? Order::query()->where('status', 'pending')->firstOrFail();
        $order->forceFill(['user_id' => $customer->id, 'type' => Order::TYPE_IMMEDIATE, 'scheduled_for' => null])->save();

        $service = app(OrderAdminService::class);
        $service->accept($order->fresh(), $admin);
        $service->assignDriver($order->fresh(), $driver, $admin);

        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->deviceTokenId === $customerToken->id);
        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->deviceTokenId === $driverToken->id
            && ($job->message['data']['order_id'] ?? null) === (string) $order->id);
        // Payloads carry identifiers and display text only; never the device token.
        Queue::assertNotPushed(SendPushMessage::class, fn (SendPushMessage $job) => str_contains(json_encode($job->message), 'fcm-'));
    }
}
