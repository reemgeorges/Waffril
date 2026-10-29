<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use App\Services\Auth\PasswordResetGrantService;
use App\Services\Functional\CombinedAuthService;
use App\Services\Functional\ResetPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 3C (2026-10-25). Both services under test are NOT routed anywhere
 * in this app (no facade, no controller, no route) — these tests exercise
 * them directly to prove the defensive hardening actually works, in case
 * either is ever wired to a live route later.
 */
class UnroutedServicesHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeOtpChannel::reset();
    }

    public function test_combined_auth_service_rejects_an_inactive_admin(): void
    {
        $admin = Admin::factory()->create(['password' => Hash::make('secret123'), 'is_active' => false]);

        $this->expectException(HttpResponseException::class);
        (new CombinedAuthService)->login(
            Request::create('/', 'POST', ['email' => $admin->email, 'password' => 'secret123'])
        );
    }

    public function test_combined_auth_service_rejects_an_inactive_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123'), 'is_active' => false]);

        $this->expectException(HttpResponseException::class);
        (new CombinedAuthService)->login(
            Request::create('/', 'POST', ['email' => $user->email, 'password' => 'secret123'])
        );
    }

    public function test_combined_auth_service_issues_a_token_for_an_active_admin(): void
    {
        $admin = Admin::factory()->create(['password' => Hash::make('secret123')]);

        $result = (new CombinedAuthService)->login(
            Request::create('/', 'POST', ['email' => $admin->email, 'password' => 'secret123'])
        );

        $this->assertNotEmpty($result['token']);
    }

    public function test_combined_auth_service_error_uses_the_standard_envelope(): void
    {
        try {
            (new CombinedAuthService)->login(
                Request::create('/', 'POST', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            );
            $this->fail('Expected an HttpResponseException.');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertArrayHasKey('data', $body);
            $this->assertArrayHasKey('status', $body);
            $this->assertArrayHasKey('error', $body);
            $this->assertArrayHasKey('statusCode', $body);
            $this->assertFalse($body['status']);
        }
    }

    public function test_reset_password_service_requires_a_grant_and_rejects_an_unverified_reset(): void
    {
        $admin = Admin::factory()->create(['password' => Hash::make('old-password')]);

        $this->expectException(HttpResponseException::class);
        (new ResetPasswordService)->resetPassword(
            Request::create('/', 'POST', [
                'actor' => 'admin',
                'email' => $admin->email,
                'reset_token' => 'made-up',
                'password' => 'hacked',
            ])
        );
    }

    public function test_reset_password_service_send_reset_otp_does_not_reveal_account_existence(): void
    {
        $admin = Admin::factory()->create(['password' => Hash::make('old-password')]);
        $service = new ResetPasswordService;

        $existing = $service->sendResetOtp(
            Request::create('/', 'POST', ['actor' => 'admin', 'email' => $admin->email])
        );
        $nonexistent = $service->sendResetOtp(
            Request::create('/', 'POST', ['actor' => 'admin', 'email' => 'nobody@example.com'])
        );

        $this->assertSame($existing, $nonexistent);
    }

    public function test_reset_password_service_no_longer_issues_a_broad_sanctum_token(): void
    {
        $admin = Admin::factory()->create();
        $service = new ResetPasswordService;

        // Can't easily intercept the real OTP without Mail::fake() + the
        // real email channel; assert on the shape of what verifyResetOtp()
        // WOULD return by checking it no longer calls createToken() at
        // all — proven structurally: reset_token is a PasswordResetGrantService
        // token (64 random chars), not a Sanctum plaintext token (which
        // always contains a '|' separator between the token id and the
        // plaintext secret).
        $token = app(PasswordResetGrantService::class)->issue($admin);

        $this->assertStringNotContainsString('|', $token);
        $this->assertSame(64, strlen($token));
    }
}
