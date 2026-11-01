<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

/**
 * WAFFRLI MySQL verification (2026-10-31) — the real Filament login form on
 * the dedicated admin_web session guard: only active, non-deleted admins
 * get in; customers, wrong passwords and disabled admins are refused; the
 * API guard is untouched.
 */
class AdminPanelLoginTest extends AdminPanelTestCase
{
    protected function admin(array $attributes = []): Admin
    {
        return Admin::factory()->create(array_merge(['email' => 'staff@example.test', 'password' => 'correct-horse-1', 'is_active' => true], $attributes));
    }

    public function test_active_admin_logs_in_on_the_admin_web_guard_only(): void
    {
        $admin = $this->admin();
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.test', 'password' => 'correct-horse-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'admin_web');
        $this->assertGuest('web');
        $this->get('/admin')->assertOk();
    }

    public function test_wrong_password_is_refused(): void
    {
        $this->admin();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.test', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('admin_web');
    }

    public function test_customer_credentials_cannot_open_the_panel(): void
    {
        User::factory()->create(['email' => 'customer@example.test', 'password' => 'correct-horse-1']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'customer@example.test', 'password' => 'correct-horse-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('admin_web');
    }

    public function test_inactive_and_deleted_admins_are_refused(): void
    {
        $this->admin(['is_active' => false]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.test', 'password' => 'correct-horse-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('admin_web');

        $deleted = $this->admin(['email' => 'gone@example.test']);
        $deleted->delete();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'gone@example.test', 'password' => 'correct-horse-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('admin_web');
    }

    public function test_roles_and_permissions_live_on_the_admin_guard(): void
    {
        $employee = $this->employee(['orders.view']);
        $this->actingAsAdmin($employee);

        $this->assertSame(['admin'], $employee->permissions->pluck('guard_name')->unique()->values()->all());
        $this->assertTrue($employee->hasPermissionTo('orders.view', 'admin'));
        $this->assertFalse($employee->hasPermissionTo('orders.accept', 'admin'));
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/admins')->assertForbidden();
    }
}
