<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use App\Services\Messaging\FakeTextGateway;
use Database\Seeders\WaffrliDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WAFFRLI customer API (2026-11-03) — shared helpers. Customer tests run on
 * the seeded demo data; OTP codes are read from the FAKE text transport.
 */
abstract class CustomerTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seedDemo = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        FakeTextGateway::$failSmsFor = [];
        if ($this->seedDemo) {
            $this->seed(WaffrliDemoSeeder::class);
        }
    }

    protected function otpFor(string $phone): string
    {
        $last = FakeTextGateway::last($phone);
        $this->assertNotNull($last, "No OTP text was sent to {$phone}.");
        preg_match('/\b(\d{6})\b/', $last['message'], $m);

        return $m[1];
    }

    protected function clearOtpLimits(): void
    {
        RateLimiter::clear('customer-otp');
        $this->travel(2)->minutes(); // past the resend cooldown
    }

    /** Full OTP login for an existing customer; returns the bearer token. */
    protected function loginCustomer(User $user, string $deviceId = 'device-1'): string
    {
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $user->phone])->assertOk();

        return $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $user->phone, 'code' => $this->otpFor($user->phone), 'device_id' => $deviceId])
            ->assertOk()->assertJsonPath('data.status', 'logged_in')->json('data.token');
    }

    protected function demoCustomer(int $n): User
    {
        return User::query()->where('email', sprintf('customer-%02d@demo.waffrli.test', $n))->firstOrFail();
    }

    protected function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }
}
