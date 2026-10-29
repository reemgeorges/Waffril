<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AuthModulesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11A (2026-10-26) — regression test for a real, live bug in
 * app/Support/HasDynamicFiltering.php: its `?active=` filter hardcoded
 * the column name to `status`, but the only two models that actually
 * use this trait (App\Models\Admin and App\Models\User, via
 * BaseAuthModel) both use `is_active` — confirmed by their own
 * migrations and by BaseAuthModel::scopeActive()/scopeInactive(), which
 * already used `is_active` a few lines above where this trait gets
 * mixed in. BaseAuthModel's OWN copy of scopeWithFilters() is commented
 * out, so the trait's version is the only one that ever actually runs
 * for Admin/User — this was a live bug on
 * GET /api/v1/admin/users/all?active=0|1, not a theoretical one.
 */
class HasDynamicFilteringActiveColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function authorizedAdminToken(): string
    {
        (new AuthModulesPermissionSeeder)->run();

        $admin = Admin::factory()->create();
        $admin->assignRole('manage-user');

        return $admin->createToken('t')->plainTextToken;
    }

    public function test_active_equals_one_returns_only_active_users(): void
    {
        $token = $this->authorizedAdminToken();

        User::factory()->count(2)->create(['is_active' => true]);
        User::factory()->create(['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all?active=1');

        $response->assertStatus(200, $response->getContent());
        $users = $response->json('data.users');
        $this->assertCount(2, $users, $response->getContent());
        foreach ($users as $user) {
            $this->assertTrue($user['is_active']);
        }
    }

    public function test_active_equals_zero_returns_only_inactive_users(): void
    {
        $token = $this->authorizedAdminToken();

        User::factory()->count(2)->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all?active=0');

        $response->assertStatus(200, $response->getContent());
        $users = $response->json('data.users');
        $this->assertCount(1, $users, $response->getContent());
        $this->assertSame($inactive->id, $users[0]['id']);
        $this->assertFalse($users[0]['is_active']);
    }

    /**
     * The soft-delete `?status=` filter (0=onlyTrashed, 2=withTrashed)
     * is a SEPARATE mechanism from `?active=` and must still work
     * unaffected by the column-name fix above.
     */
    public function test_status_soft_delete_filter_is_unaffected_by_the_active_column_fix(): void
    {
        $token = $this->authorizedAdminToken();

        $kept = User::factory()->create(['is_active' => true]);
        $trashed = User::factory()->create(['is_active' => true]);
        $trashed->delete();

        $default = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all');
        $default->assertStatus(200);
        $defaultIds = collect($default->json('data.users'))->pluck('id');
        $this->assertTrue($defaultIds->contains($kept->id));
        $this->assertFalse($defaultIds->contains($trashed->id), 'Trashed users must be excluded by default.');

        $onlyTrashed = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all?status=0');
        $onlyTrashed->assertStatus(200);
        $onlyTrashedIds = collect($onlyTrashed->json('data.users'))->pluck('id');
        $this->assertTrue($onlyTrashedIds->contains($trashed->id));
        $this->assertFalse($onlyTrashedIds->contains($kept->id));

        $withTrashed = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/users/all?status=2');
        $withTrashed->assertStatus(200);
        $withTrashedIds = collect($withTrashed->json('data.users'))->pluck('id');
        $this->assertTrue($withTrashedIds->contains($kept->id));
        $this->assertTrue($withTrashedIds->contains($trashed->id));
    }
}
