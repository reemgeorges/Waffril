<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\PreservesGeneratedDocs;
use Tests\TestCase;

/**
 * Phase 8 (2026-10-26): `make:module --with-openapi`'s per-module
 * fragment, `php artisan openapi:build` (aggregation + optional Postman
 * export), and `php artisan docs:check-drift` (documented-vs-registered
 * verification).
 *
 * Writes real files under docs/openapi/ and docs/postman/ (there is no
 * sandboxed output root for a doc-generation command any more than there
 * was for make:module itself) — tearDown() removes everything these
 * tests could have created, regardless of pass/fail, the same discipline
 * tests/Feature/MakeModuleCommandTest.php already established.
 *
 * Uses its own disposable module names ("DocsGizmo", "DocsWidget" — a
 * separate pair from MakeModuleCommandTest's "Gadget*" names, so the two
 * test classes' module generations never collide if a future test run
 * ever interleaves them) for the same PHP class-identity reason
 * documented at length in MakeModuleCommandTest's own class doc comment.
 * These tests only ever inspect generated file CONTENT (never real class
 * behavior/HTTP), so that reasoning doesn't actually bite here — the
 * separate names are kept anyway, simply for clarity against the other
 * file's fixtures.
 */
class OpenApiAndPostmanGenerationTest extends TestCase
{
    use PreservesGeneratedDocs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotGeneratedDocs();
    }

    protected array $names = ['DocsGizmo', 'DocsWidget'];

    protected array $tables = ['docs_gizmos', 'docs_widgets'];

    protected array $routeFiles = ['docs-gizmo', 'docs-widget'];

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            foreach (File::glob(database_path("migrations/*_create_{$table}_table.php")) as $path) {
                File::delete($path);
            }
        }

        foreach ($this->names as $i => $name) {
            $routeFile = $this->routeFiles[$i];

            foreach ([
                app_path("Models/{$name}.php"),
                app_path("Http/Requests/Model/Store{$name}Request.php"),
                database_path("factories/{$name}Factory.php"),
                app_path("Facades/Services/{$name}/{$name}Facade.php"),
                app_path("Http/Resources/Model/{$name}Resource.php"),
                app_path("Http/Controllers/{$name}/{$name}Controller.php"),
                base_path("routes/api/v1/{$routeFile}.php"),
                base_path("docs/openapi/modules/{$routeFile}.yaml"),
            ] as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }

            foreach ([
                app_path("Services/Model/{$name}"),
                app_path("Facades/Services/{$name}"),
                app_path("Http/Controllers/{$name}"),
            ] as $dir) {
                if (File::isDirectory($dir)) {
                    File::deleteDirectory($dir);
                }
            }
        }

        $this->restoreGeneratedDocs();

        parent::tearDown();
    }

    public function test_disabled_scaffold_module_generates_an_empty_openapi_fragment(): void
    {
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $fragment = Yaml::parseFile(base_path("docs/openapi/modules/{$this->routeFiles[0]}.yaml"));

        // No --guard/--public was passed — this module's routes file only
        // has the disabled, commented-out scaffold, so there is nothing
        // live to document yet. An empty `paths` here (rather than a
        // fragment claiming endpoints that don't actually exist) is
        // exactly what keeps `docs:check-drift` meaningful.
        $this->assertSame([], $fragment['paths']);
        $this->assertArrayHasKey($this->names[0], $fragment['components']['schemas']);
    }

    public function test_live_guarded_module_generates_an_accurate_openapi_fragment(): void
    {
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required;price:decimal:8,2:required',
            '--guard' => 'sanctum',
            '--crud' => 'create,read,update,delete',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $fragmentPath = base_path("docs/openapi/modules/{$this->routeFiles[0]}.yaml");
        $raw = File::get($fragmentPath);
        $fragment = Yaml::parseFile($fragmentPath);

        $prefix = "/api/v1/{$this->routeFiles[0]}";
        $this->assertArrayHasKey("{$prefix}/all/paginated", $fragment['paths']);
        $this->assertArrayHasKey("{$prefix}/create", $fragment['paths']);
        $this->assertArrayHasKey("{$prefix}/delete", $fragment['paths']);
        // activate/deactivate are UNCONDITIONAL in scaffold:routes' own
        // base route set (see openApiStub()'s own doc comment) — --crud
        // never actually removes them, only ever ADDS delete/restore/
        // forceDelete on top. Must be documented as present even though
        // --crud above didn't name them.
        $this->assertArrayHasKey("{$prefix}/activate", $fragment['paths']);
        // restore/force-delete genuinely were NOT requested via --crud —
        // must NOT be documented as if they existed.
        $this->assertArrayNotHasKey("{$prefix}/restore", $fragment['paths']);
        $this->assertArrayNotHasKey("{$prefix}/force-delete", $fragment['paths']);

        $this->assertNotEmpty($fragment['paths']["{$prefix}/create"]['post']['security'] ?? null);

        $createSchema = $fragment['paths']["{$prefix}/create"]['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['title', 'price'], $createSchema['required']);
        $this->assertSame('number', $createSchema['properties']['price']['type']);

        // Phase 11E: pagination query params are genuinely always
        // available on /all/paginated — must be documented.
        $paginatedParams = collect($fragment['paths']["{$prefix}/all/paginated"]['get']['parameters'] ?? [])->pluck('name');
        $this->assertTrue($paginatedParams->contains('page'));
        $this->assertTrue($paginatedParams->contains('per_page'));
        // No --relations on this module — ?include= must NOT be
        // documented as if some relation were includable.
        $this->assertFalse($paginatedParams->contains('include'));

        // The real bug this test guards against: PHP auto-casts a '200'
        // array key to the integer 200, so Symfony's Yaml::dump() would
        // otherwise emit a bare `200:` instead of the OpenAPI-required
        // quoted string key `'200':` — found by reading the raw generated
        // text during this phase's own manual verification.
        $this->assertStringContainsString("'200':", $raw);
        $this->assertDoesNotMatchRegularExpression('/^\s*200:$/m', $raw);
    }

    public function test_openapi_build_aggregates_fragments_and_fixes_duplicate_paths_safely(): void
    {
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $this->artisan('make:module', [
            'name' => $this->names[1],
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $this->artisan('openapi:build')->assertExitCode(0);

        $document = Yaml::parseFile(base_path('docs/openapi/openapi.yaml'));

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertArrayHasKey($this->names[0], $document['components']['schemas']);
        $this->assertArrayHasKey($this->names[1], $document['components']['schemas']);
        $this->assertArrayHasKey("/api/v1/{$this->routeFiles[0]}/all/paginated", $document['paths']);
        $this->assertArrayHasKey("/api/v1/{$this->routeFiles[1]}/all/paginated", $document['paths']);

        // Re-running must fully rebuild (not append/duplicate) — the
        // aggregate is a derived build artifact, always regenerated whole.
        $this->artisan('openapi:build')->assertExitCode(0);
        $rebuilt = Yaml::parseFile(base_path('docs/openapi/openapi.yaml'));
        $this->assertSame(count($document['paths']), count($rebuilt['paths']));
    }

    public function test_openapi_build_with_postman_emits_a_matching_collection_and_blank_credential_environment(): void
    {
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $this->artisan('openapi:build', ['--postman' => true])->assertExitCode(0);

        $document = Yaml::parseFile(base_path('docs/openapi/openapi.yaml'));
        $collection = json_decode(File::get(base_path('docs/postman/collection.json')), true);
        $environment = json_decode(File::get(base_path('docs/postman/environment.json')), true);

        // The collection must describe exactly the same endpoint set the
        // OpenAPI document does — both built from the SAME aggregated
        // $paths in the same command invocation.
        $this->assertCount(count($document['paths']), $collection['item']);

        $hasBearerAuthItem = collect($collection['item'])->contains(
            fn ($item) => ($item['request']['auth']['type'] ?? null) === 'bearer'
        );
        $this->assertTrue($hasBearerAuthItem, 'Expected at least one guarded request to carry bearer auth in the Postman collection.');

        // No real credential anywhere in the environment template.
        $bearerVar = collect($environment['values'])->firstWhere('key', 'bearer_token');
        $this->assertSame('', $bearerVar['value']);
        $baseUrlVar = collect($environment['values'])->firstWhere('key', 'base_url');
        $this->assertNotEmpty($baseUrlVar['value']);
    }

    public function test_docs_check_drift_detects_missing_and_stale_documentation(): void
    {
        // Missing docs: a real route exists (the disabled scaffold has
        // none, so generate a LIVE module) with no fragment aggregated.
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->routeFiles[0]}.php"));

        // An empty aggregate (no fragments at all) — every real route for
        // this module is therefore "registered but not documented".
        File::ensureDirectoryExists(base_path('docs/openapi/modules'));
        File::put(base_path('docs/openapi/openapi.yaml'), Yaml::dump(['openapi' => '3.0.3', 'paths' => []]));

        $missing = $this->artisan('docs:check-drift');
        $missing->assertExitCode(1);

        // Stale docs: a fragment claims a path that was never actually
        // registered as a live route.
        File::put(base_path('docs/openapi/openapi.yaml'), Yaml::dump([
            'openapi' => '3.0.3',
            'paths' => ['/api/v1/totally-not-a-real-registered-route' => ['get' => ['summary' => 'fake']]],
        ]));
        $stale = $this->artisan('docs:check-drift');
        $stale->assertExitCode(1);
    }

    /**
     * NOT a full exit-code-0 assertion: this app also has real,
     * hand-written auth routes (routes/api/v1/admin.php, user.php —
     * Phases 2-3) that are registered at every boot, test or real, but
     * have no OpenAPI fragment at all (make:auth-module, unlike
     * make:module, doesn't generate one) — so a totally clean
     * `docs:check-drift` isn't achievable from this test alone, and
     * isn't what it's actually proving. What matters here is that THIS
     * module's own real routes are no longer reported as
     * "registered but not documented" once its fragment exists and has
     * been aggregated — the intended, real, zero-drift workflow for a
     * `make:module`-generated module specifically.
     */
    public function test_docs_check_drift_reports_no_missing_docs_for_a_module_that_used_with_openapi(): void
    {
        $this->artisan('make:module', [
            'name' => $this->names[0],
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--crud' => 'create,read',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->routeFiles[0]}.php"));

        $this->artisan('openapi:build')->assertExitCode(0);

        // Artisan::call()+output() (rather than $this->artisan(...)
        // chaining) is what actually exposes the command's printed text
        // for inspection in a Laravel test.
        Artisan::call('docs:check-drift');
        $output = Artisan::output();

        $this->assertStringNotContainsString($this->routeFiles[0], $output);
    }
}
