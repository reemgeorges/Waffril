<?php

namespace Tests\Feature\Push;

use App\Models\DeviceToken;
use App\Models\Driver;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Database\Seeders\WaffrliDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notification audit (2026-11-13): push-token lifecycle for drivers.
 *
 * - A device is identified by device_id. Registering a refreshed FCM token
 *   from the same device replaces the device's old token instead of adding
 *   a second active row: otherwise one phone receives every push twice, and
 *   the stale duplicates use up the 3-device limit and push a driver's other
 *   real phone out of delivery offers.
 * - Signing out stops pushes to that device (as it already did for
 *   customers): a phone that was handed over must not keep receiving
 *   delivery offers.
 */
class DeviceTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'test-only-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WaffrliDemoSeeder::class);
    }

    protected function login(Driver $driver, string $device = 'E2E'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => self::PASSWORD, 'device_name' => $device])
            ->assertOk()->json('data.token');
    }

    protected function register(string $bearer, string $token, string $device): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json'])
            ->postJson('/api/v1/driver/device-tokens', ['token' => 'fcm-test-token-'.str_repeat('x', 20).'-'.$token, 'platform' => 'android', 'device_id' => $device, 'device_name' => 'Phone '.$device])
            ->assertOk();
    }

    /** @return list<string> the active device_ids, one entry per active token */
    protected function activeDevices(Driver $driver): array
    {
        return DeviceToken::query()->where('tokenable_type', $driver->getMorphClass())->where('tokenable_id', $driver->id)
            ->where('is_active', true)->orderBy('device_id')->pluck('device_id')->all();
    }

    protected function driver(): Driver
    {
        $driver = DemoPeopleSeeder::driver(3);
        $driver->forceFill(['password' => self::PASSWORD])->save();

        return $driver->fresh();
    }

    public function test_a_refreshed_token_replaces_the_old_token_of_the_same_device(): void
    {
        $driver = $this->driver();
        $bearer = $this->login($driver);

        $this->register($bearer, 'phone1-a', 'phone-1');
        $this->register($bearer, 'phone2-a', 'phone-2');
        $this->register($bearer, 'phone1-b', 'phone-1'); // FCM token refresh on phone 1
        $this->register($bearer, 'phone1-c', 'phone-1'); // and again
        $this->register($bearer, 'phone3-a', 'phone-3');

        // One live token per phone, and phone 2 is still reachable.
        $this->assertSame(['phone-1', 'phone-2', 'phone-3'], $this->activeDevices($driver));
        $this->assertStringEndsWith('phone1-c', DeviceToken::query()->where('device_id', 'phone-1')->where('is_active', true)->value('token'));
    }

    public function test_signing_out_stops_pushes_to_that_device_only(): void
    {
        $driver = $this->driver();
        $phone1 = $this->login($driver, 'phone-1');
        $phone2 = $this->login($driver, 'phone-2');
        $this->register($phone1, 'p1', 'phone-1');
        $this->register($phone2, 'p2', 'phone-2');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$phone1, 'Accept' => 'application/json'])
            ->postJson('/api/v1/driver/logout', ['device_id' => 'phone-1'])->assertOk();

        $this->assertSame(['phone-2'], $this->activeDevices($driver));
    }

    public function test_signing_out_without_a_device_id_stops_all_pushes_of_that_driver(): void
    {
        $driver = $this->driver();
        $bearer = $this->login($driver);
        $this->register($bearer, 'p1', 'phone-1');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json'])
            ->postJson('/api/v1/driver/logout')->assertOk();

        $this->assertSame([], $this->activeDevices($driver));
    }
}
