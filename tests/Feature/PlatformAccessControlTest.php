<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\Auth\PlatformPermissions;
use Database\Seeders\PlatformPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\PlatformTestHelpers;
use Tests\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — every P0 admin route is closed to
 * anonymous callers, customer tokens and admins lacking the specific
 * permission; customer routes are closed to anonymous and admin tokens.
 */
class PlatformAccessControlTest extends TestCase
{
    use PlatformTestHelpers, RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function adminRoutes(): array
    {
        return [
            'settings list' => ['get', '/api/v1/admin/settings/all', PlatformPermissions::SETTINGS_MANAGE],
            'settings create' => ['post', '/api/v1/admin/settings/create', PlatformPermissions::SETTINGS_MANAGE],
            'currencies list' => ['get', '/api/v1/admin/currencies/all/paginated', PlatformPermissions::CURRENCIES_MANAGE],
            'currencies create' => ['post', '/api/v1/admin/currencies/create', PlatformPermissions::CURRENCIES_MANAGE],
            'currencies initialize default' => ['post', '/api/v1/admin/currencies/initialize-default', PlatformPermissions::CURRENCIES_MANAGE],
            'cities list' => ['get', '/api/v1/admin/cities/all', PlatformPermissions::CITIES_MANAGE],
            'cities deactivate' => ['post', '/api/v1/admin/cities/deactivate', PlatformPermissions::CITIES_MANAGE],
            'areas create' => ['post', '/api/v1/admin/delivery-areas/create', PlatformPermissions::DELIVERY_MANAGE],
            'fee rules list' => ['get', '/api/v1/admin/delivery-fee-rules/all', PlatformPermissions::DELIVERY_MANAGE],
            'fee rules quote' => ['post', '/api/v1/admin/delivery-fee-rules/quote', PlatformPermissions::DELIVERY_MANAGE],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_routes_reject_anonymous_callers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_routes_reject_customer_tokens(string $method, string $uri): void
    {
        $this->asBearer($this->userToken())->json($method, $uri)->assertStatus(401);
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_routes_reject_admins_without_the_permission(string $method, string $uri): void
    {
        $this->asBearer($this->adminToken())->json($method, $uri)->assertStatus(403);
    }

    #[DataProvider('adminRoutes')]
    public function test_a_different_permission_does_not_grant_access(string $method, string $uri, string $permission): void
    {
        $others = array_values(array_diff(PlatformPermissions::all(), [$permission]));

        $this->asBearer($this->adminToken($others))->json($method, $uri)->assertStatus(403);
    }

    #[DataProvider('adminRoutes')]
    public function test_the_matching_permission_passes_authorization(string $method, string $uri, string $permission): void
    {
        $status = $this->asBearer($this->adminToken([$permission]))->json($method, $uri)->status();

        $this->assertNotContains($status, [401, 403], "{$uri} should be authorized for {$permission}");
    }

    public function test_customer_city_routes_reject_anonymous_and_admin_tokens(): void
    {
        $this->getJson('/api/v1/user/cities')->assertStatus(401);
        $this->postJson('/api/v1/user/cities/detect', ['lat' => 1, 'lng' => 1])->assertStatus(401);

        $adminToken = $this->adminToken(PlatformPermissions::all());
        $this->asBearer($adminToken)->getJson('/api/v1/user/cities')->assertStatus(401);
    }

    public function test_permission_seeder_is_idempotent_and_never_assigns(): void
    {
        (new PlatformPermissionSeeder)->run();
        (new PlatformPermissionSeeder)->run();

        $this->assertSame(
            count(PlatformPermissions::all()),
            Permission::query()->where('guard_name', 'admin')->whereIn('name', PlatformPermissions::all())->count()
        );

        $admin = Admin::factory()->create();
        foreach (PlatformPermissions::all() as $permission) {
            $this->assertFalse($admin->checkPermissionTo($permission, 'admin'));
        }
    }

    public function test_no_p0_route_can_switch_or_delete(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()
            ->filter(fn ($uri) => preg_match('#^api/v1/admin/(settings|currencies|cities|delivery-areas|delivery-fee-rules)/#', $uri));

        $this->assertNotEmpty($uris);
        foreach ($uris as $uri) {
            $this->assertDoesNotMatchRegularExpression('#/(delete|destroy|force-?delete|restore|change-default|switch-default)$#', $uri);
        }
    }
}
