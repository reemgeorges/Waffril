<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Database\Seeders\AdminAccessSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFFRLI (2026-10-31) — base for admin-panel tests: seeds the permission
 * catalogue and logs in through the `admin_web` guard.
 */
abstract class AdminPanelTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new AdminAccessSeeder)->run();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    protected function employee(array $permissions = []): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        return $admin;
    }

    protected function actingAsAdmin(Admin $admin): static
    {
        return $this->actingAs($admin, 'admin_web');
    }
}
