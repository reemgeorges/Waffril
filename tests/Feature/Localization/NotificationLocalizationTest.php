<?php

namespace Tests\Feature\Localization;

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerOrderUpdate;
use App\Notifications\DriverOrderUpdate;
use App\Services\Order\OrderAdminService;
use App\Services\Push\DeviceTokenService;
use App\Services\Push\FakePushTransport;
use App\Support\Broadcasting\RecordingBroadcaster;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-04) — notifications speak the RECIPIENT's language:
 * FCM push and live messages to customers/drivers, admin bell alerts per
 * admin; stored notifications keep both languages; Reverb events keep
 * stable machine values and carry both display labels.
 */
class NotificationLocalizationTest extends CustomerTestCase
{
    protected function admin(array $permissions = ['*'], ?string $locale = null): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true, 'locale' => $locale]);
        $permissions === ['*'] ? $admin->assignRole(Admin::SUPER_ADMIN_ROLE) : $admin->givePermissionTo($permissions);

        return $admin;
    }

    protected function device(object $owner, string $suffix): string
    {
        $token = 'fcm-locale-test-token-'.str_repeat('z', 12).$suffix;
        app(DeviceTokenService::class)->register($owner, ['token' => $token, 'platform' => 'android', 'device_id' => 'd-'.$suffix]);

        return $token;
    }

    public function test_customers_get_push_in_their_own_language_regardless_of_the_admin_language(): void
    {
        config(['waffrli.push.enabled' => true]);
        $order = Order::where('order_number', 'DEMO-0023')->firstOrFail();
        $customer = User::findOrFail($order->user_id);

        foreach (['ar', 'en'] as $language) {
            $customer->forceFill(['language' => $language])->save();
            FakePushTransport::reset();
            $token = $this->device($customer->fresh(), $language);
            app()->setLocale($language === 'ar' ? 'en' : 'ar'); // the admin works in the OTHER language

            $notification = new CustomerOrderUpdate('accepted', $order);
            $customer->fresh()->notify($notification);

            $push = FakePushTransport::to($token)[0]['message'];
            $this->assertSame(__('notifications.customer.order.accepted', ['number' => $order->order_number], $language), $push['body']);
            $this->assertSame(__('notifications.customer.order_title', ['number' => $order->order_number], $language), $push['title']);
            $this->assertSame('order.accepted', $push['data']['event'], 'Event identifiers are never translated.');
        }

        // Stored with both languages; the API shows the caller's language.
        $stored = $customer->notifications()->latest()->first();
        $this->assertSame(['ar', 'en'], array_keys(collect($stored->data['message'])->sortKeys()->all()));
        $token = $this->loginCustomer($customer->fresh());
        foreach (['ar', 'en'] as $locale) {
            $item = $this->api($token)->withHeader('Accept-Language', $locale)->getJson('/api/v1/customer/notifications')->assertOk()->json('data.notifications.0');
            $this->assertSame($stored->data['message'][$locale], $item['message']);
        }
    }

    public function test_drivers_get_push_in_their_language_and_admin_alerts_follow_each_admin(): void
    {
        config(['waffrli.push.enabled' => true]);
        $driver = DemoPeopleSeeder::driver(5);
        $driver->forceFill(['language' => 'en'])->save();
        $token = $this->device($driver->fresh(), 'driver');
        app()->setLocale('ar');

        $order = Order::where('order_number', 'DEMO-0031')->firstOrFail();
        $driver->fresh()->notify(new DriverOrderUpdate('assigned', $order));
        $this->assertSame(__('notifications.driver.order.assigned', ['number' => $order->order_number], 'en'), FakePushTransport::to($token)[0]['message']['body']);

        // Bell alerts: each admin in their own language.
        $arabic = $this->admin(['orders.view'], 'ar');
        $english = $this->admin(['orders.view'], 'en');
        $driverOne = DemoPeopleSeeder::driver(1);
        $driverOne->forceFill(['password' => 'test-only-password-1'])->save();
        $bearer = $this->postJson('/api/v1/driver/login', ['phone' => $driverOne->phone, 'password' => 'test-only-password-1'])->json('data.token');
        $this->api($bearer)->withHeader('Accept-Language', 'en')
            ->postJson("/api/v1/driver/orders/{$order->id}/problems", ['type' => 'wrong_address'])->assertOk();

        $title = fn (Admin $admin) => $admin->notifications()->where('type', 'Filament\\Notifications\\DatabaseNotification')->latest()->first()->data['title'];
        $this->assertSame(__('admin.alerts.order_problem_reported.title', [], 'ar'), $title($arabic));
        $this->assertSame(__('admin.alerts.order_problem_reported.title', [], 'en'), $title($english));
    }

    public function test_reverb_status_events_keep_machine_values_and_carry_both_labels(): void
    {
        $order = Order::where('order_number', 'DEMO-0023')->firstOrFail();
        app()->setLocale('en');
        app(OrderAdminService::class)->accept($order, $this->admin());

        $payload = RecordingBroadcaster::on("private-order.{$order->id}", 'order.status_changed')[0]['payload'];
        $this->assertSame('accepted', $payload['status']);
        $this->assertSame(['ar' => __('admin.order_status.accepted', [], 'ar'), 'en' => __('admin.order_status.accepted', [], 'en')], $payload['status_labels']);
    }
}
