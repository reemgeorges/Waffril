<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-05) — model $hidden attributes (password hashes, remember
 * tokens) never appear in API responses. BasicResource previously serialized
 * them (wrong array check), e.g. in the admin API login and users list.
 */
class HiddenAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_and_users_list_never_expose_password_hashes(): void
    {
        (new AdminAccessSeeder)->run();
        $admin = Admin::factory()->create(['email' => 'api-admin@example.test', 'password' => 'api-test-password-1', 'is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);
        User::factory()->create(['password' => 'customer-password-1']);

        $login = $this->postJson('/api/v1/admin/login', ['email' => 'api-admin@example.test', 'password' => 'api-test-password-1'])->assertOk();
        $this->assertArrayNotHasKey('password', $login->json('data.admin'));
        $this->assertArrayNotHasKey('remember_token', $login->json('data.admin'));
        $this->assertStringNotContainsString('$2y$', $login->getContent(), 'No bcrypt hash anywhere in the response.');

        $users = $this->withToken($login->json('data.token'))->getJson('/api/v1/admin/users/all')->assertOk();
        $this->assertStringNotContainsString('$2y$', $users->getContent());
        $this->assertStringNotContainsString('"password"', $users->getContent());
    }
}
