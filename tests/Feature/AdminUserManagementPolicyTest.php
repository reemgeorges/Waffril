<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AuthModulesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3C (2026-10-25) — "wire relevant endpoint policies without default
 * grants." Proves app/Policies/UserPolicy.php (dead weight since Phase 2B)
 * is now actually enforced by routes/api/v1/admin.php's users/* routes,
 * and that no admin is ever granted access by anything automatic.
 */
class AdminUserManagementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_without_the_role_is_denied_every_action(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $target = User::factory()->create(['is_active' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/users/deactivate', ['id' => $target->id])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/users/activate', ['id' => $target->id])
            ->assertStatus(403);

        // Nothing changed.
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_the_manage_user_role_is_never_assigned_automatically(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $admin = Admin::factory()->create();

        $this->assertFalse($admin->hasRole('manage-user'), 'Seeding roles must never also assign them.');
    }

    public function test_an_admin_with_the_explicitly_assigned_role_can_manage_users(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $admin = Admin::factory()->create();
        $admin->assignRole('manage-user');
        $token = $admin->createToken('t')->plainTextToken;

        $target = User::factory()->create(['is_active' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all')
            ->assertStatus(200);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/users/deactivate', ['id' => $target->id])
            ->assertStatus(200);
        $this->assertFalse($target->fresh()->is_active);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/users/activate', ['id' => $target->id])
            ->assertStatus(200);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_a_user_token_cannot_reach_these_admin_only_routes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all')
            ->assertStatus(401);
    }

    public function test_having_the_manage_admin_role_does_not_grant_manage_user(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $admin = Admin::factory()->create();
        $admin->assignRole('manage-admin');
        $token = $admin->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all')
            ->assertStatus(403);
    }
}
