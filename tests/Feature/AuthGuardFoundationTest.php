<?php

namespace Tests\Feature;

use App\Http\Requests\Model\Auth\LoginRequest;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 2A (2026-10-25) — Configurable Authentication Foundation.
 *
 * Covers: guard/provider configuration, guard isolation (a token from one
 * model cannot authenticate as the other guard), inactive/soft-deleted
 * accounts being rejected at login, and the now-fixed, already-live
 * admin/login + admin/logout endpoints (previously fatal: App\Models\Admin
 * and App\Models\DeviceToken did not exist).
 */
class AuthGuardFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_user_guards_are_configured_with_distinct_providers(): void
    {
        $this->assertSame('sanctum', config('auth.guards.admin.driver'));
        $this->assertSame('admins', config('auth.guards.admin.provider'));
        $this->assertSame('sanctum', config('auth.guards.user.driver'));
        $this->assertSame('users', config('auth.guards.user.provider'));

        $this->assertSame(Admin::class, config('auth.providers.admins.model'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    public function test_admin_token_is_rejected_by_a_user_guarded_route(): void
    {
        Route::middleware(['auth:user', 'guard.identity:user'])
            ->get('/__test/guard-foundation/user-only', fn () => response()->json(['ok' => true]));

        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/guard-foundation/user-only')
            ->assertStatus(401);
    }

    public function test_user_token_is_rejected_by_an_admin_guarded_route(): void
    {
        Route::middleware(['auth:admin', 'guard.identity:admin'])
            ->get('/__test/guard-foundation/admin-only', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/guard-foundation/admin-only')
            ->assertStatus(401);
    }

    public function test_matching_token_passes_its_own_guarded_route(): void
    {
        Route::middleware(['auth:admin', 'guard.identity:admin'])
            ->get('/__test/guard-foundation/admin-only-2', fn () => response()->json(['ok' => true]));

        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/guard-foundation/admin-only-2')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_unauthenticated_request_is_rejected_in_api_envelope(): void
    {
        Route::middleware(['auth:admin', 'guard.identity:admin'])
            ->get('/__test/guard-foundation/admin-only-3', fn () => response()->json(['ok' => true]));

        $response = $this->getJson('/__test/guard-foundation/admin-only-3');

        $response->assertStatus(401);
        $response->assertJsonStructure(['data', 'status', 'error', 'statusCode']);
        $response->assertJson(['status' => false, 'statusCode' => 401]);
    }

    public function test_inactive_user_cannot_login_at_the_service_layer(): void
    {
        Route::post('/__test/guard-foundation/user-login', function (LoginRequest $request) {
            try {
                return response()->json(app('UserAuthService')->login($request));
            } catch (HttpResponseException $e) {
                return $e->getResponse();
            }
        });

        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => false,
            'status' => true,
        ]);

        $response = $this->postJson('/__test/guard-foundation/user-login', [
            'email' => 'inactive@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_inactive_admin_cannot_login_via_the_real_http_endpoint(): void
    {
        $admin = Admin::factory()->create([
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_soft_deleted_admin_cannot_login(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'gone@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $admin->delete();

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'gone@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => false]);
    }

    public function test_admin_login_endpoint_works_end_to_end(): void
    {
        $admin = Admin::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_admin_logout_accepts_matching_admin_token(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/logout')
            ->assertStatus(200)
            ->assertJson(['status' => true]);
    }

    public function test_admin_logout_rejects_a_user_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/logout')
            ->assertStatus(401);
    }

    public function test_issued_tokens_carry_the_guard_ability(): void
    {
        $admin = Admin::factory()->create();
        $accessToken = $admin->createToken('access-token', ['admin', '*']);

        $this->assertTrue($accessToken->accessToken->can('admin'));
    }
}
