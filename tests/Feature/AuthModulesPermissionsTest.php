<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AuthModulesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2B (2026-10-25). Verifies Spatie roles/permissions resolve the
 * correct guard_name per auth module, that the seeder is idempotent, and
 * that the auto-discovered Admin/User policies actually gate access.
 */
class AuthModulesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_seeder_creates_one_role_per_module_with_correct_guard(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        // Both roles are held by Admin actors, so both live under the
        // configured management guard ('admin'), not each module's own
        // guard — see config('auth_modules.management_guard').
        $this->assertDatabaseHas('roles', ['name' => 'manage-admin', 'guard_name' => 'admin']);
        $this->assertDatabaseHas('roles', ['name' => 'manage-user', 'guard_name' => 'admin']);
    }

    public function test_permission_seeder_is_idempotent(): void
    {
        (new AuthModulesPermissionSeeder)->run();
        (new AuthModulesPermissionSeeder)->run();

        $this->assertSame(1, Role::where('name', 'manage-admin')->count());
    }

    public function test_admin_with_role_can_manage_other_admins_via_policy(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $actor = Admin::factory()->create();
        $actor->assignRole('manage-admin');

        $target = Admin::factory()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('update', $target));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $target));
    }

    public function test_admin_without_role_cannot_manage_admins_via_policy(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $actor = Admin::factory()->create();
        $target = Admin::factory()->create();

        $this->assertFalse(Gate::forUser($actor)->allows('update', $target));
    }

    public function test_admin_can_never_force_delete_via_policy_regardless_of_role(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $actor = Admin::factory()->create();
        $actor->assignRole('manage-admin');
        $target = Admin::factory()->create();

        $this->assertFalse(Gate::forUser($actor)->allows('forceDelete', $target));
    }

    public function test_admin_with_role_can_manage_user_accounts_via_policy(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $admin = Admin::factory()->create();
        $admin->assignRole('manage-user');

        $user = User::factory()->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $user));
    }

    public function test_user_actor_cannot_manage_user_accounts_via_the_admin_user_policy(): void
    {
        (new AuthModulesPermissionSeeder)->run();

        $actorUser = User::factory()->create(['is_active' => true]);
        $targetUser = User::factory()->create(['is_active' => true]);

        $this->assertFalse(Gate::forUser($actorUser)->allows('update', $targetUser));
    }
}
