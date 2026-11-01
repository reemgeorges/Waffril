<?php

namespace Tests\Feature\Driver;

use App\Enums\OrderStatus;
use App\Filament\Pages\DriverMapPage;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Driver;
use App\Services\Driver\DriverDeliveryService;
use App\Services\Order\OrderAdminService;
use App\Support\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI driver API (2026-11-02) — driver API together with the admin side:
 * wallet-paid orders, admin assignment notifications, the Filament map and
 * delivery-photo download, and the dev-only demo password command.
 */
class DriverWorkflowIntegrationTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

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

    public function test_wallet_paid_order_is_not_collected_again_as_cash(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $customer = $this->customer();
        $this->fundWallet($customer, $branch, '20', $admin);
        $driver = $this->driver($city);

        $order = $this->order($customer, $branch, '12', ['wallet' => '12']);
        $service = app(OrderAdminService::class);
        $service->accept($order, $admin);
        $service->assignDriver($order, $driver, $admin);
        $token = $this->tokenFor($driver);

        $payment = $this->api($token)->getJson("/api/v1/driver/orders/{$order->id}")->assertOk()->json('data.order.payment');
        $this->assertSame('0.00', $payment['cash_to_collect']);
        $this->assertSame('12.00', $payment['prepaid_amount']);

        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '12'])
            ->assertStatus(422)->assertJsonPath('error', __('messages.driver.cash_not_due'));
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver")->assertOk()->assertJsonPath('data.order.status', 'delivered');

        $this->assertSame(0, $driver->collectedPayments()->count(), 'No cash collection is recorded for a wallet-paid order.');
        $this->assertSame('8.0000', (string) $customer->wallets()->first()->balance);
    }

    public function test_mixed_wallet_and_cash_order_collects_only_the_cash_part(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $customer = $this->customer();
        $this->fundWallet($customer, $branch, '5', $admin);
        $driver = $this->driver($city);
        $order = $this->order($customer, $branch, '12', ['wallet' => '5']);
        app(OrderAdminService::class)->accept($order, $admin);
        app(OrderAdminService::class)->assignDriver($order, $driver, $admin);
        $token = $this->tokenFor($driver);

        $payment = $this->api($token)->getJson("/api/v1/driver/orders/{$order->id}")->json('data.order.payment');
        $this->assertSame('7.00', $payment['cash_to_collect']);
        $this->assertSame('5.00', $payment['prepaid_amount']);
        $this->assertSame('12.00', $payment['order_total']);

        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '12'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '7.0000'])->assertOk();
        $this->assertSame('7.0000', Money::toStorage((string) $driver->collectedPayments()->sum('collected_amount')));
    }

    public function test_assignment_notification_location_and_admin_map_stay_in_sync(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $driver = $this->driver($city);
        $order = $this->order($this->customer(), $branch, '9');
        app(OrderAdminService::class)->accept($order, $admin);
        app(OrderAdminService::class)->assignDriver($order, $driver, $admin);
        $token = $this->tokenFor($driver);

        $notifications = $this->api($token)->getJson('/api/v1/driver/notifications')->assertOk()->json('data.notifications');
        $this->assertSame('assigned', $notifications[0]['event']);
        $this->assertSame($order->order_number, $notifications[0]['order_number']);

        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.4321, 'lng' => 36.1234])->assertOk();

        $this->actingAsAdmin($admin);
        $marker = collect(Livewire::test(DriverMapPage::class)->instance()->markers())->firstWhere('id', $driver->id);
        $this->assertSame(33.4321, $marker['lat']);
        $this->assertSame(36.1234, $marker['lng']);
        $this->assertFalse($marker['stale']);

        // Unassigning notifies the driver and stops tracking.
        app(OrderAdminService::class)->unassignDriver($order, $admin);
        $this->assertSame(1, $driver->notifications()->where('type', 'driver.order_unassigned')->count());
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.43, 'lng' => 36.12])->assertStatus(409);
        $this->actingAsAdmin($admin); // the API helper reset the guards
        $this->assertNull(collect(Livewire::test(DriverMapPage::class)->instance()->markers())->firstWhere('id', $driver->id));
    }

    public function test_admin_can_download_the_delivery_photo(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $driver = $this->driver($city);
        $order = $this->order($this->customer(), $branch, '9');
        app(OrderAdminService::class)->accept($order, $admin);
        app(OrderAdminService::class)->assignDriver($order, $driver, $admin);
        $token = $this->tokenFor($driver);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();
        $this->api($token)->post("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '9', 'photo' => UploadedFile::fake()->image('proof.png')])->assertOk();

        $path = $order->fresh()->delivery_photo_path;
        Storage::disk(DriverDeliveryService::PHOTO_DISK)->assertExists($path);
        $this->assertStringStartsWith('delivery-proofs/'.$order->id.'/', $path);

        $this->actingAsAdmin($admin);
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('deliveryPhoto')
            ->callAction('deliveryPhoto')
            ->assertFileDownloaded();

        $this->actingAsAdmin($this->employee(['customers.view']));
        // Drop the driver API headers (Bearer + Accept: JSON) before using the web panel.
        $this->flushHeaders()->get('/admin/orders/'.$order->id)->assertForbidden();
    }

    public function test_invalid_photo_is_rejected_and_nothing_changes(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $driver = $this->driver($city);
        $order = $this->order($this->customer(), $branch, '9');
        app(OrderAdminService::class)->accept($order, $admin);
        app(OrderAdminService::class)->assignDriver($order, $driver, $admin);
        $token = $this->tokenFor($driver);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();

        $this->api($token)->post("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '9', 'photo' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])
            ->assertStatus(400);
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_demo_driver_password_command_is_dev_only_and_demo_only(): void
    {
        $city = $this->city();
        $real = $this->driver($city, ['phone' => '+963944000111']);
        $demo = $this->driver($city, ['phone' => '+12025550205']);
        $demo->createToken('old', ['driver', '*']);

        $this->artisan('waffrli:demo-driver-password', ['driver' => $real->id])->assertFailed();

        $this->artisan('waffrli:demo-driver-password', ['driver' => $demo->id])
            ->expectsQuestion('New password (min 10 characters, hidden)', 'chosen-locally-1')
            ->expectsQuestion('Repeat the password', 'mismatch-value-1')
            ->assertFailed();

        $this->artisan('waffrli:demo-driver-password', ['driver' => $demo->phone])
            ->expectsQuestion('New password (min 10 characters, hidden)', 'chosen-locally-1')
            ->expectsQuestion('Repeat the password', 'chosen-locally-1')
            ->doesntExpectOutputToContain('chosen-locally-1')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('chosen-locally-1', $demo->fresh()->password));
        $this->assertSame(0, $demo->tokens()->count());
        $this->postJson('/api/v1/driver/login', ['phone' => $demo->phone, 'password' => 'chosen-locally-1'])->assertOk();

        $this->app['env'] = 'production';
        $this->artisan('waffrli:demo-driver-password', ['driver' => $demo->id])->assertFailed();
        $this->assertTrue(Hash::check('chosen-locally-1', $demo->fresh()->password));
    }
}
