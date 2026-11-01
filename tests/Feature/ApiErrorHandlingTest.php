<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-05): unknown API routes answer 404 (they used to answer
 * 500 through the generic catch-all), wrong methods 405, both in the
 * standard envelope; genuine failures still answer 500 and never leak
 * details in production; authentication on real routes is unchanged.
 */
class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function assertEnvelope($response, int $status): void
    {
        $response->assertStatus($status)
            ->assertExactJson(['data' => null, 'status' => false, 'error' => $response->json('error'), 'statusCode' => $status]);
        $this->assertIsString($response->json('error'));
        $this->assertNotSame('', $response->json('error'));
    }

    /** @return array<string, array{string, string}> */
    public static function unknownRoutes(): array
    {
        return [
            'customer' => ['GET', '/api/v1/customer/does-not-exist'],
            'customer nested' => ['POST', '/api/v1/customer/orders/1/teleport'],
            'driver' => ['GET', '/api/v1/driver/does-not-exist'],
            'driver nested' => ['PATCH', '/api/v1/driver/orders/1/fly'],
            'platform admin' => ['GET', '/api/v1/admin/does-not-exist'],
            'platform admin crud' => ['POST', '/api/v1/admin/cities/destroy-everything'],
            'user alias' => ['GET', '/api/v1/user/does-not-exist'],
            'api root' => ['GET', '/api/v1/nope'],
            'non-numeric id' => ['GET', '/api/v1/customer/orders/abc'],
        ];
    }

    /** @dataProvider unknownRoutes */
    public function test_unknown_api_routes_answer_404_in_the_envelope(string $method, string $uri): void
    {
        $this->assertEnvelope($this->json($method, $uri), 404);
        // A token does not change it: the route does not exist.
        $this->assertEnvelope($this->withHeader('Authorization', 'Bearer 1|not-a-real-token')->json($method, $uri), 404);
    }

    public function test_unknown_route_message_does_not_echo_the_path_and_is_localized(): void
    {
        $en = $this->json('GET', '/api/v1/customer/secret-admin-path');
        $this->assertStringNotContainsString('secret-admin-path', (string) $en->getContent());
        $this->assertSame(__('messages.http.not_found', [], 'en'), $en->json('error'));

        $ar = $this->withHeader('Accept-Language', 'ar')->json('GET', '/api/v1/customer/secret-admin-path');
        $this->assertSame(__('messages.http.not_found', [], 'ar'), $ar->json('error'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar->json('error'));
    }

    public function test_wrong_method_on_an_existing_route_answers_405(): void
    {
        $this->assertEnvelope($this->json('DELETE', '/api/v1/driver/login'), 405);
        $this->assertEnvelope($this->json('GET', '/api/v1/customer/auth/otp/request'), 405);
    }

    public function test_authentication_semantics_on_real_routes_are_unchanged(): void
    {
        $this->assertEnvelope($this->json('GET', '/api/v1/customer/me'), 401);
        $this->assertEnvelope($this->json('GET', '/api/v1/driver/me'), 401);
        $this->assertEnvelope($this->json('GET', '/api/v1/admin/cities/all'), 401);
        $this->assertEnvelope($this->withHeader('Authorization', 'Bearer 1|not-a-real-token')->json('GET', '/api/v1/customer/me'), 401);
    }

    public function test_genuine_internal_errors_still_answer_500(): void
    {
        Route::middleware('api')->get('/api/v1/__test/boom', fn () => throw new RuntimeException('database password is hunter2'));

        $this->assertEnvelope($this->json('GET', '/api/v1/__test/boom'), 500);
    }

    public function test_internal_error_details_are_hidden_in_production(): void
    {
        Route::middleware('api')->get('/api/v1/__test/boom', fn () => throw new RuntimeException('database password is hunter2'));
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->json('GET', '/api/v1/__test/boom');
        $this->assertEnvelope($response, 500);
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function test_explicit_abort_messages_and_statuses_are_kept(): void
    {
        Route::middleware('api')->get('/api/v1/__test/gone', fn () => abort(410, 'This offer has ended.'));
        Route::middleware('api')->get('/api/v1/__test/denied', fn () => abort(403));

        $gone = $this->json('GET', '/api/v1/__test/gone');
        $this->assertEnvelope($gone, 410);
        $this->assertSame('This offer has ended.', $gone->json('error'));
        $this->assertEnvelope($this->json('GET', '/api/v1/__test/denied'), 403);
    }
}
