<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 0 security fix regression test.
 *
 * routes/api.php used to expose fully public, unauthenticated CRUD routes
 * (including create/update/activate/deactivate) for User, Test and Category
 * with no middleware at all. They have been disabled (commented out) because
 * no working admin/user auth guard exists yet (see routes/api.php TODO).
 * These tests prove the endpoints are no longer publicly reachable.
 */
class PublicCrudRoutesDisabledTest extends TestCase
{
    public static function disabledRoutesProvider(): array
    {
        return [
            'user all' => ['GET', '/api/user/all'],
            'user all paginated' => ['GET', '/api/user/all/paginated'],
            'user show' => ['POST', '/api/user/show'],
            'user create' => ['POST', '/api/user/create'],
            'user update' => ['POST', '/api/user/update'],
            'user activate' => ['POST', '/api/user/activate'],
            'user deactivate' => ['POST', '/api/user/deactivate'],

            'test all' => ['GET', '/api/test/all'],
            'test all paginated' => ['GET', '/api/test/all/paginated'],
            'test show' => ['POST', '/api/test/show'],
            'test create' => ['POST', '/api/test/create'],
            'test update' => ['POST', '/api/test/update'],
            'test activate' => ['POST', '/api/test/activate'],
            'test deactivate' => ['POST', '/api/test/deactivate'],

            'category all' => ['GET', '/api/category/all'],
            'category all paginated' => ['GET', '/api/category/all/paginated'],
            'category show' => ['POST', '/api/category/show'],
            'category create' => ['POST', '/api/category/create'],
            'category update' => ['POST', '/api/category/update'],
            'category activate' => ['POST', '/api/category/activate'],
            'category deactivate' => ['POST', '/api/category/deactivate'],
        ];
    }

    #[DataProvider('disabledRoutesProvider')]
    public function test_previously_public_crud_route_is_no_longer_reachable(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'email_verified_at' => now()->toDateTimeString(),
            'password' => 'password',
        ]);

        // The route was removed, so the router throws NotFoundHttpException.
        // WAFFRLI (2026-11-05): that now answers 404 in the standard envelope
        // (it used to fall through to the generic 500 branch — a defect, see
        // tests/Feature/ApiErrorHandlingTest). The request never reaches the
        // controller/service layer and never returns a 2xx.
        $response->assertStatus(404);
        $response->assertJsonStructure(['data', 'status', 'error', 'statusCode']);
        $response->assertJsonPath('status', false);
        $response->assertJsonPath('data', null);
    }

    public function test_authenticated_sanctum_user_route_is_still_reachable(): void
    {
        // Sanity check: make sure we did not accidentally disable unrelated
        // routes. The guarded /api/user route (not part of the disabled
        // public CRUD group) must still require authentication (401/419-ish
        // via Laravel's unauthenticated() handler), not 404.
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJsonStructure(['data', 'status', 'error', 'statusCode']);
    }
}
