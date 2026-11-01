<?php

namespace Tests\Feature;

use App\Console\Commands\Waffrli\ApiInventoryCommand;
use App\Console\Commands\Waffrli\BuildOpenApiCommand;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The committed OpenAPI document (docs/waffrli/openapi/waffrli-api.yaml) is
 * generated from the routes, their FormRequest rules and real Bruno
 * examples; the Scalar viewer at /docs is opt-in and never in production.
 */
class OpenApiDocumentTest extends TestCase
{
    public function test_committed_document_matches_routes_rules_and_examples(): void
    {
        $this->artisan('waffrli:openapi', ['--check' => true])->assertSuccessful();
    }

    public function test_document_lists_every_implemented_route_and_nothing_else(): void
    {
        $doc = Yaml::parseFile(base_path(BuildOpenApiCommand::PATH));
        $documented = collect($doc['paths'])->flatMap(fn ($ops, $path) => collect(array_keys($ops))->map(fn ($m) => strtoupper($m).' '.$path))->sort()->values()->all();
        $expected = collect(ApiInventoryCommand::apiRoutes())
            ->reject(fn ($r) => ApiInventoryCommand::optional($r[1], $r[2]))
            ->map(fn ($r) => $r[0].' /'.$r[1])->sort()->values()->all();

        $this->assertSame($expected, $documented);
        $this->assertStringNotContainsString('/api/v1/dev/', implode("\n", $documented));
    }

    public function test_examples_contain_no_secrets(): void
    {
        $raw = file_get_contents(base_path(BuildOpenApiCommand::PATH)).file_get_contents(base_path(BuildOpenApiCommand::EXAMPLES));

        $this->assertDoesNotMatchRegularExpression('/\b\d+\|[A-Za-z0-9]{30,}\b/', $raw, 'Sanctum token in docs');
        $this->assertDoesNotMatchRegularExpression('/\$2[aby]\$\d{2}\$/', $raw, 'password hash in docs');
        $this->assertDoesNotMatchRegularExpression('/"(password|token|registration_token|auth)":\s*"(?!<redacted>)[^"]+"/', $raw);
    }

    public function test_request_bodies_come_from_the_validation_rules(): void
    {
        $doc = Yaml::parseFile(base_path(BuildOpenApiCommand::PATH));
        $city = $doc['paths']['/api/v1/admin/cities/create']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertEqualsCanonicalizing(['center_lat', 'center_lng', 'name', 'radius_km'], $city['required']);
        $this->assertSame(['ar', 'en'], array_keys($city['properties']['name']['properties']));

        $otp = $doc['paths']['/api/v1/customer/auth/otp/request']['post'];
        $this->assertSame([], $otp['security'], 'public route');
        $this->assertSame(['phone'], $otp['requestBody']['content']['application/json']['schema']['required']);
        $this->assertSame([['customerToken' => []]], $doc['paths']['/api/v1/customer/me']['get']['security']);
        $this->assertSame([['driverToken' => []]], $doc['paths']['/api/v1/driver/me']['get']['security']);
        $this->assertSame([['adminToken' => []]], $doc['paths']['/api/v1/admin/cities/all']['get']['security']);
    }

    public function test_docs_viewer_is_not_registered_by_default(): void
    {
        $this->get('/docs')->assertNotFound();
        $this->get('/docs/openapi.yaml')->assertNotFound();
    }

    public function test_docs_viewer_when_enabled_outside_production(): void
    {
        config(['waffrli.api_docs.enabled' => true]);
        Route::middleware('web')->group(base_path('routes/web.php'));
        app('router')->getRoutes()->refreshNameLookups();

        $this->get('/docs')->assertOk()->assertSee('WAFFRLI API reference')->assertDontSee('cdn.', false);
        $this->get('/docs/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=utf-8');
    }

    public function test_docs_viewer_never_registered_in_production(): void
    {
        config(['waffrli.api_docs.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        Route::middleware('web')->group(base_path('routes/web.php'));

        $this->assertFalse(Route::has('api-docs'));
    }
}
