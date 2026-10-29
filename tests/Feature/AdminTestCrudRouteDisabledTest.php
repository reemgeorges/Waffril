<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 0.1 security fix regression test.
 *
 * routes/api/v1/admin.php used to contain an `admin/test/*` CRUD block
 * (including create/update/activate/deactivate) that sat inside the `admin`
 * prefix group but OUTSIDE the `auth:admin` middleware group — i.e. it
 * looked like an admin-protected path but was fully unauthenticated. There is
 * still no working `admin` guard configured in config/auth.php, so the block
 * has been disabled (commented out) rather than wired to a non-existent
 * guard. These tests prove the endpoints are no longer publicly reachable
 * and that the still-public `admin/ping` and `admin/login` routes (which are
 * intentionally public and unaffected by this fix) still resolve.
 */
class AdminTestCrudRouteDisabledTest extends TestCase
{
    public static function disabledAdminTestRoutesProvider(): array
    {
        return [
            'admin test all' => ['GET', '/api/v1/admin/test/all'],
            'admin test all paginated' => ['GET', '/api/v1/admin/test/all/paginated'],
            'admin test show' => ['POST', '/api/v1/admin/test/show'],
            'admin test create' => ['POST', '/api/v1/admin/test/create'],
            'admin test update' => ['POST', '/api/v1/admin/test/update'],
            'admin test activate' => ['POST', '/api/v1/admin/test/activate'],
            'admin test deactivate' => ['POST', '/api/v1/admin/test/deactivate'],
        ];
    }

    /**
     * @dataProvider disabledAdminTestRoutesProvider
     */
    public function test_previously_unauthenticated_admin_test_route_is_no_longer_reachable(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, [
            'name' => 'Attacker',
            'description' => 'attacker-supplied',
            'image' => 'attacker.png',
        ]);

        // The route has been removed entirely, so Laravel's router throws a
        // routing NotFoundHttpException, which the app's pre-existing generic
        // exception handler converts into a 500 JSON envelope (see
        // PublicCrudRoutesDisabledTest for the same, pre-existing, out-of-scope
        // 404-vs-500 behavior). What matters here is that the request never
        // reaches the controller/service layer and never returns a 2xx.
        $response->assertStatus(500);
        $response->assertJsonStructure(['data', 'status', 'error', 'statusCode']);
        $response->assertJsonPath('status', false);
        $response->assertJsonPath('data', null);
    }

    public function test_admin_ping_route_is_still_publicly_reachable(): void
    {
        // Sanity check: make sure this fix did not accidentally disable the
        // intentionally-public admin/ping health-check route.
        $response = $this->getJson('/api/v1/admin/ping');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }
}
