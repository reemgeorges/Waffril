<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 3B (2026-10-25). Exercises the REAL routes in
 * routes/api/v1/{user,admin}.php (not temporary test-local routes) to
 * prove they are genuinely live, correctly wired end-to-end, and
 * guard-isolated — "enable only fully implemented and tested routes".
 */
class AuthRoutesLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FakeOtpChannel::reset();
    }

    public function test_user_register_login_and_logout_live_routes(): void
    {
        $register = $this->postJson('/api/v1/user/register', [
            'name' => 'Live User',
            'email' => 'live-user@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'otp_delivery_method' => 'fake',
        ]);
        $register->assertStatus(200);
        $register->assertJsonStructure(['data', 'status', 'error', 'statusCode']);

        $user = User::where('email', 'live-user@example.com')->first();
        $code = FakeOtpChannel::lastCodeFor($user, 'register');
        $this->assertNotNull($code);

        $verify = $this->postJson('/api/v1/user/register/verify', [
            'id' => $user->id,
            'otp' => $code,
            'purpose' => 'register',
        ]);
        $verify->assertStatus(200);

        $login = $this->postJson('/api/v1/user/login', [
            'email' => 'live-user@example.com',
            'password' => 'secret123',
        ]);
        $login->assertStatus(200);
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/logout')
            ->assertStatus(200);
    }

    public function test_user_password_reset_live_routes_end_to_end(): void
    {
        $user = User::factory()->create([
            'email' => 'live-reset@example.com',
            'password' => Hash::make('old-password'),
            'is_active' => true,
            'status' => true,
            'otp_delivery_method' => 'fake',
        ]);

        $this->postJson('/api/v1/user/password/send-otp', [
            'actor' => 'user',
            'email' => $user->email,
            'channel' => 'fake',
        ])->assertStatus(200);

        $code = FakeOtpChannel::lastCodeFor($user, 'reset_password');

        $verify = $this->postJson('/api/v1/user/password/verify-otp', [
            'actor' => 'user',
            'email' => $user->email,
            'otp' => $code,
        ]);
        $verify->assertStatus(200);
        $resetToken = $verify->json('data.reset_token');
        $this->assertNotEmpty($resetToken);

        $this->postJson('/api/v1/user/password/reset', [
            'actor' => 'user',
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_user_phone_and_email_change_live_routes_require_user_guard(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/request-phone-change', ['channel' => 'fake'])
            ->assertStatus(200);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/request-email-change')
            ->assertStatus(200);
    }

    public function test_admin_token_cannot_reach_user_only_authenticated_routes(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/request-phone-change', ['channel' => 'fake'])
            ->assertStatus(401);
    }

    public function test_admin_password_reset_live_routes_end_to_end(): void
    {
        $admin = Admin::factory()->create(['password' => Hash::make('old-admin-pass')]);

        $this->postJson('/api/v1/admin/password/send-otp', [
            'actor' => 'admin',
            'email' => $admin->email,
            'channel' => 'email',
        ])->assertStatus(200);

        // Admin always uses the real 'email' channel — confirm the flow is
        // live and enforced by proving a wrong code is rejected (the exact
        // code isn't observable without Mail::fake(), covered already in
        // tests/Feature/AuthFlowsIntegrationTest.php's temp-route version).
        $this->postJson('/api/v1/admin/password/verify-otp', [
            'actor' => 'admin',
            'email' => $admin->email,
            'otp' => '000000',
        ])->assertStatus(400);
    }

    public function test_user_registration_route_rejects_invalid_otp_channel(): void
    {
        $response = $this->postJson('/api/v1/user/register', [
            'name' => 'Bad Channel',
            'email' => 'badchannel@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'otp_delivery_method' => 'carrier-pigeon',
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('users', ['email' => 'badchannel@example.com']);
    }

    public function test_phone_change_route_rejects_a_malformed_destination(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        // requestPhoneChange/verifyPhoneChangeOtp previously type-hinted
        // the plain Request — no validation at all. Both are now real
        // FormRequests (Phase 3C).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/request-phone-change', ['channel' => 'not-a-real-channel'])
            ->assertStatus(400);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/verify-phone-change-otp', [
                'otp' => '123456',
                'prefix_phone' => 'not-digits',
                'phone_number' => '5551234567',
            ])
            ->assertStatus(400);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/verify-phone-change-otp', ['otp' => 'abc'])
            ->assertStatus(400);
    }

    public function test_email_change_route_rejects_a_malformed_destination(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/user/verify-email-change-otp', [
                'otp' => '123456',
                'email' => 'not-an-email',
            ])
            ->assertStatus(400);
    }

    public function test_login_route_is_rate_limited(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('secret123')]);

        // Phase 3C: 'auth-login' allows 10/minute per (ip, identifier).
        // Wrong password each time — these count against the limiter
        // regardless of outcome, same as a real brute-force attempt would.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/user/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(400);
        }

        $this->postJson('/api/v1/user/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_otp_endpoint_rate_limit_is_independent_per_identifier(): void
    {
        // Isolate the HTTP-layer 'auth-otp-endpoint' limiter (5/minute)
        // being tested here from App\Services\Auth\OtpService's own,
        // separately-tested cooldown/send-rate-limit (also defaulting to
        // numbers around 5) — both must not need to coincide for this
        // test to mean what it says.
        config(['otp.resend_cooldown_seconds' => 0, 'otp.max_sends' => 1000]);

        $userA = User::factory()->create(['is_active' => true, 'otp_delivery_method' => 'fake']);
        $userB = User::factory()->create(['is_active' => true, 'otp_delivery_method' => 'fake']);

        // Phase 3C: 'auth-otp-endpoint' allows 5/minute per (ip, identifier)
        // — exhausting it for userA must not affect userB.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/user/password/send-otp', [
                'actor' => 'user',
                'email' => $userA->email,
                'channel' => 'fake',
            ]);
        }

        $this->postJson('/api/v1/user/password/send-otp', [
            'actor' => 'user',
            'email' => $userA->email,
            'channel' => 'fake',
        ])->assertStatus(429);

        $this->postJson('/api/v1/user/password/send-otp', [
            'actor' => 'user',
            'email' => $userB->email,
            'channel' => 'fake',
        ])->assertStatus(200);
    }

    public function test_login_challenge_route_is_disabled_by_default(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Registered unconditionally (see routes/api/v1/user.php's doc
        // comment), but config('auth_modules.modules.user.require_login_challenge')
        // defaults to false, so login() never withholds a token, and
        // calling verify directly is rejected by
        // BaseAuthService::otpActionAllowed('login_challenge').
        $response = $this->postJson('/api/v1/user/login-challenge/verify', [
            'email' => $user->email,
            'otp' => '123456',
            'purpose' => 'register',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_challenge_route_works_end_to_end_once_enabled(): void
    {
        config([
            'auth_modules.modules.user.require_login_challenge' => true,
            'auth_modules.modules.user.otp.actions' => ['login_challenge'],
        ]);

        $user = User::factory()->create([
            'email' => 'challenge-live@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
            'status' => true,
            'otp_delivery_method' => 'fake',
        ]);

        $login = $this->postJson('/api/v1/user/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);
        $login->assertStatus(200);
        $this->assertNull($login->json('data.token'));
        $this->assertTrue($login->json('data.need_login_challenge'));

        $code = FakeOtpChannel::lastCodeFor($user, 'login_challenge');
        $this->assertNotNull($code);

        $verify = $this->postJson('/api/v1/user/login-challenge/verify', [
            'email' => $user->email,
            'otp' => $code,
            'purpose' => 'register',
        ]);
        $verify->assertStatus(200);
        $this->assertNotEmpty($verify->json('data.token'));
    }
}
