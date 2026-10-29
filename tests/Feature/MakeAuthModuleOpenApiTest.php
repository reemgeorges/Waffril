<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Phase 11E (2026-10-26): `make:auth-module --with-openapi` — a
 * confirmed gap from Phase 8's own roadmap notes ("make:auth-module-
 * generated routes have no OpenAPI generation at all"). Snapshot-and-
 * restore for `config/auth_modules.php` (the same pattern
 * `MakeAuthModuleCommandTest` already established) — `make:auth-module`
 * edits that real, committed file in place.
 */
class MakeAuthModuleOpenApiTest extends TestCase
{
    protected string $name = 'DocsAuthGizmo';

    protected string $guard = 'docs_auth_gizmo';

    protected string $table = 'docs_auth_gizmos';

    protected string $routeFile = 'docs-auth-gizmo';

    protected string $authModulesConfigPath;

    protected string $authModulesConfigOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authModulesConfigPath = config_path('auth_modules.php');
        $this->authModulesConfigOriginal = File::get($this->authModulesConfigPath);
    }

    protected function tearDown(): void
    {
        File::put($this->authModulesConfigPath, $this->authModulesConfigOriginal);

        if (Schema::hasTable($this->table)) {
            Schema::dropIfExists($this->table);
        }
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')
                ->where('migration', 'like', "%_create_{$this->table}_table")
                ->delete();
        }
        foreach (File::glob(database_path("migrations/*_create_{$this->table}_table.php")) as $path) {
            File::delete($path);
        }

        foreach ([
            app_path("Models/{$this->name}.php"),
            database_path("factories/{$this->name}Factory.php"),
            app_path("Services/Functional/{$this->name}AuthService.php"),
            app_path("Http/Controllers/Auth/{$this->name}AuthController.php"),
            app_path("Http/Resources/Model/{$this->name}Resource.php"),
            app_path("Facades/Services/Auth/{$this->name}AuthFacade.php"),
            app_path("Policies/{$this->name}Policy.php"),
            base_path("routes/api/v1/{$this->routeFile}.php"),
            base_path("docs/openapi/modules/{$this->routeFile}.yaml"),
        ] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        if (File::isDirectory(base_path('docs/openapi'))) {
            File::deleteDirectory(base_path('docs/openapi'));
        }

        parent::tearDown();
    }

    public function test_disabled_auth_module_generates_an_empty_openapi_fragment(): void
    {
        $this->artisan('make:auth-module', [
            'name' => $this->name,
            '--otp' => true,
            '--registration' => true,
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $fragment = Yaml::parseFile(base_path("docs/openapi/modules/{$this->routeFile}.yaml"));

        // No --enable-routes — nothing live to document yet.
        $this->assertSame([], $fragment['paths']);
        $this->assertArrayHasKey($this->name, $fragment['components']['schemas']);
    }

    public function test_enabled_auth_module_generates_an_accurate_openapi_fragment_from_real_request_classes(): void
    {
        $this->artisan('make:auth-module', [
            'name' => $this->name,
            '--login' => 'email',
            '--otp' => true,
            '--registration' => true,
            '--enable-routes' => true,
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $fragmentPath = base_path("docs/openapi/modules/{$this->routeFile}.yaml");
        $fragment = Yaml::parseFile($fragmentPath);

        $prefix = "/api/v1/{$this->routeFile}";

        // Public, unguarded endpoints.
        $this->assertArrayHasKey("{$prefix}/login", $fragment['paths']);
        $this->assertArrayNotHasKey('security', $fragment['paths']["{$prefix}/login"]['post']);
        $this->assertArrayHasKey("{$prefix}/register", $fragment['paths']);
        $this->assertArrayHasKey("{$prefix}/register/verify", $fragment['paths']);

        // Guarded endpoint (logout).
        $this->assertArrayHasKey("{$prefix}/logout", $fragment['paths']);
        $this->assertNotEmpty($fragment['paths']["{$prefix}/logout"]['post']['security'] ?? null);
        // logout has no request body at all — must not fabricate an
        // empty one.
        $this->assertArrayNotHasKey('requestBody', $fragment['paths']["{$prefix}/logout"]['post']);

        // reset_password wasn't requested via --otp-actions' default
        // (only register/resend_register/reset_password are default, but
        // this module's --login=email means no phone_change) — confirm
        // the fragment only documents what's REALLY live.
        $this->assertArrayNotHasKey("{$prefix}/request-phone-change", $fragment['paths']);

        // The register schema must reflect RegisterRequest's REAL fields
        // (read via reflection, not hand-duplicated) — including which
        // ones are actually required.
        $registerSchema = $fragment['paths']["{$prefix}/register"]['post']['requestBody']['content']['application/json']['schema'];
        $this->assertArrayHasKey('name', $registerSchema['properties']);
        $this->assertArrayHasKey('otp_delivery_method', $registerSchema['properties']);
        $this->assertContains('otp_delivery_method', $registerSchema['required']);
        $this->assertContains('name', $registerSchema['required']);
        // email is nullable (phone is the alternative identifier) — must
        // NOT be falsely marked required.
        $this->assertNotContains('email', $registerSchema['required']);
    }

    public function test_openapi_build_aggregates_both_module_types_together(): void
    {
        $this->artisan('make:auth-module', [
            'name' => $this->name,
            '--login' => 'email',
            '--otp' => true,
            '--registration' => true,
            '--enable-routes' => true,
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $this->artisan('make:module', [
            'name' => 'DocsAuthItem',
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        try {
            $this->artisan('openapi:build')->assertExitCode(0);

            $document = Yaml::parseFile(base_path('docs/openapi/openapi.yaml'));
            $this->assertArrayHasKey($this->name, $document['components']['schemas']);
            $this->assertArrayHasKey('DocsAuthItem', $document['components']['schemas']);
            $this->assertArrayHasKey("/api/v1/{$this->routeFile}/login", $document['paths']);
            $this->assertArrayHasKey('/api/v1/docs-auth-item/all/paginated', $document['paths']);
        } finally {
            foreach ([
                app_path('Models/DocsAuthItem.php'),
                app_path('Http/Requests/Model/StoreDocsAuthItemRequest.php'),
                database_path('factories/DocsAuthItemFactory.php'),
                app_path('Facades/Services/DocsAuthItem/DocsAuthItemFacade.php'),
                app_path('Http/Resources/Model/DocsAuthItemResource.php'),
                app_path('Http/Controllers/DocsAuthItem/DocsAuthItemController.php'),
                base_path('routes/api/v1/docs-auth-item.php'),
                base_path('docs/openapi/modules/docs-auth-item.yaml'),
            ] as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
            foreach ([
                app_path('Services/Model/DocsAuthItem'),
                app_path('Facades/Services/DocsAuthItem'),
                app_path('Http/Controllers/DocsAuthItem'),
            ] as $dir) {
                if (File::isDirectory($dir)) {
                    File::deleteDirectory($dir);
                }
            }
            foreach (File::glob(database_path('migrations/*_create_docs_auth_items_table.php')) as $path) {
                File::delete($path);
            }
        }
    }

    public function test_docs_check_drift_no_longer_flags_an_auth_module_that_used_with_openapi(): void
    {
        $this->artisan('make:auth-module', [
            'name' => $this->name,
            '--login' => 'email',
            '--otp' => true,
            '--registration' => true,
            '--enable-routes' => true,
            '--with-openapi' => true,
        ])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->routeFile}.php"));

        $this->artisan('openapi:build')->assertExitCode(0);

        Artisan::call('docs:check-drift');
        $output = Artisan::output();

        $this->assertStringNotContainsString($this->routeFile, $output);
    }
}
