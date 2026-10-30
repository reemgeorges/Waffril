<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\User;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Phase 10 (2026-10-26) — end-to-end validation across three
 * representative scenarios spanning the generator suite built in
 * Phases 4-9, using disposable fixtures this test fully generates and
 * tears down itself:
 *
 *  A. Basic CRUD module: active/inactive + soft-delete/restore + search
 *     + pagination (`make:module`, Phases 1/4/5).
 *  B. A complex module: belongsTo relationship, single AND multiple
 *     image attributes, opt-in stats, --permissions-protected CRUD
 *     (`make:module --relations --images --stats --permissions`,
 *     Phases 4-7).
 *  C. An auth-enabled module: real register -> verify -> login against
 *     its OWN generated, guard-isolated routes (`make:auth-module`,
 *     Phases 2B/3B — reused, not reimplemented). Password-reset for a
 *     GENERATED auth module is already covered end-to-end elsewhere
 *     (MakeAuthModuleCommandTest) and is deliberately not duplicated
 *     here.
 *  D. (Phase 11H) A fresh-project chain: `starter:new` copies this
 *     starter kit into a disposable target directory (reusing the
 *     already-established, independently-tested behavior from
 *     StarterNewProjectCommandTest — composer install/migrate against
 *     that copy is a real side effect this suite never performs, same
 *     as that file's own documented scope boundary), and — inside THIS
 *     already-booted app, since the copy has no vendor/ to run its own
 *     artisan — a disposable module is generated with --with-openapi,
 *     aggregated via `openapi:build --postman`, checked for drift via
 *     `docs:check-drift`, and the whole project is swept with
 *     `starter:doctor`, proving the generator -> docs -> diagnostic
 *     pipeline really works together, not just each piece in isolation.
 *
 * Each scenario generates real files, runs its real migration(s) against
 * the shared sqlite `:memory:` test connection, registers its generated
 * routes file by hand (the same `bootstrap/app.php`-glob-timing
 * workaround `MakeModuleCommandTest`/`MakeAuthModuleCommandTest` already
 * establish — the file is created mid-test, after the app already
 * booted), exercises real HTTP endpoints, and is fully removed in
 * tearDown() regardless of pass/fail. Distinct disposable names from
 * every other test file's fixtures, for the same PHP class-identity
 * reason documented at length in MakeModuleCommandTest's own doc
 * comment (this file's own scenarios don't collide with each other
 * either, since each uses a different module name throughout).
 */
class StarterE2EScenariosTest extends TestCase
{
    protected string $scenarioA = 'DemoTag';

    protected string $scenarioATable = 'demo_tags';

    protected string $scenarioARoute = 'demo-tag';

    protected string $scenarioB = 'DemoItem';

    protected string $scenarioBTable = 'demo_items';

    protected string $scenarioBRoute = 'demo-item';

    protected string $scenarioC = 'DemoMember';

    /**
     * The guard name defaults to Str::snake($name) ("demo_member") when
     * --guard isn't passed, but the routes FILE and URL PREFIX are
     * Str::kebab($name) ("demo-member") instead — a real distinction
     * confirmed by generating a real module and reading its actual
     * routes file, not assumed.
     */
    protected string $scenarioCRoute = 'demo-member';

    protected string $scenarioD = 'E2eFreshGadget';

    protected string $scenarioDTable = 'e2e_fresh_gadgets';

    protected string $scenarioDRoute = 'e2e-fresh-gadget';

    protected ?string $scenarioDTarget = null;

    protected string $authModulesConfigPath;

    protected string $authModulesConfigOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        // make:auth-module edits config/auth_modules.php in place — a
        // real, committed project file, not a disposable generated one.
        // Snapshot-and-restore (the same pattern
        // MakeAuthModuleCommandTest already established) rather than
        // trying to surgically remove just the block this test adds:
        // found necessary the hard way, after an earlier interrupted run
        // of this exact test left a stray "demo_member" entry behind in
        // the real file, which this phase then had to clean up by hand.
        $this->authModulesConfigPath = config_path('auth_modules.php');
        $this->authModulesConfigOriginal = File::get($this->authModulesConfigPath);
    }

    protected function tearDown(): void
    {
        File::put($this->authModulesConfigPath, $this->authModulesConfigOriginal);

        $this->removeModule($this->scenarioA, $this->scenarioATable, $this->scenarioARoute);
        $this->removeModule($this->scenarioB, $this->scenarioBTable, $this->scenarioBRoute);
        $this->removeAuthModule($this->scenarioC);
        $this->removeModule($this->scenarioD, $this->scenarioDTable, $this->scenarioDRoute);

        $fragmentPath = base_path("docs/openapi/modules/{$this->scenarioDRoute}.yaml");
        if (File::exists($fragmentPath)) {
            File::delete($fragmentPath);
        }

        if ($this->scenarioDTarget && File::isDirectory($this->scenarioDTarget)) {
            File::deleteDirectory($this->scenarioDTarget);
        }

        $this->purgeCommittedRowsOnMySql();

        parent::tearDown();
    }

    protected function removeModule(string $name, string $table, string $routeFile): void
    {
        if (Schema::hasTable($table)) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')
                ->where('migration', 'like', "%_create_{$table}_table")
                ->delete();
        }
        foreach (File::glob(database_path("migrations/*_create_{$table}_table.php")) as $path) {
            File::delete($path);
        }

        foreach ([
            app_path("Models/{$name}.php"),
            app_path("Http/Requests/Model/Store{$name}Request.php"),
            database_path("factories/{$name}Factory.php"),
            app_path("Facades/Services/{$name}/{$name}Facade.php"),
            app_path("Policies/{$name}Policy.php"),
            app_path("Http/Resources/Model/{$name}Resource.php"),
            app_path("Http/Controllers/{$name}/{$name}Controller.php"),
            app_path("Http/Controllers/{$name}/{$name}MediaController.php"),
            app_path("Http/Controllers/{$name}/{$name}StatsController.php"),
            base_path("routes/api/v1/{$routeFile}.php"),
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

    protected function removeAuthModule(string $name): void
    {
        $table = Str::snake(Str::pluralStudly($name));
        $routeFile = Str::kebab($name);

        if (Schema::hasTable($table)) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')
                ->where('migration', 'like', "%_create_{$table}_table")
                ->delete();
        }
        foreach (File::glob(database_path("migrations/*_create_{$table}_table.php")) as $path) {
            File::delete($path);
        }

        foreach ([
            app_path("Models/{$name}.php"),
            database_path("factories/{$name}Factory.php"),
            app_path("Services/Functional/{$name}AuthService.php"),
            app_path("Http/Controllers/Auth/{$name}AuthController.php"),
            app_path("Http/Resources/Model/{$name}Resource.php"),
            app_path("Facades/Services/Auth/{$name}AuthFacade.php"),
            app_path("Policies/{$name}Policy.php"),
            base_path("routes/api/v1/{$routeFile}.php"),
            base_path("tests/Feature/{$name}AuthModuleTest.php"),
        ] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
    }

    /**
     * Scenario A: a basic module — create, list (paginated), activate/
     * deactivate independent of soft-delete, soft-delete, restore. The
     * "search" part of this scenario's description is Phase 5's own
     * documented, accepted boundary: `$search`/`$dynamicFilterColumns`
     * are empty-by-default on a freshly generated model (secure by
     * design), so search/filtering isn't exercised live here — the
     * mechanism itself is already covered by Phase 5/7's own tests; this
     * scenario's job is the basic lifecycle end-to-end.
     */
    public function test_scenario_a_basic_crud_module_full_lifecycle(): void
    {
        $this->artisan('make:module', [
            'name' => $this->scenarioA,
            '--fields' => 'name:string:required',
            '--guard' => 'sanctum',
            '--crud' => 'create,read,update,delete',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->scenarioARoute}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $create = $this->withHeaders($headers)->postJson("/api/v1/{$this->scenarioARoute}/create", ['name' => 'Alpha']);
        $create->assertStatus(200, $create->getContent());

        $modelClass = "App\\Models\\{$this->scenarioA}";
        $record = $modelClass::first();
        $this->assertNotNull($record);
        $this->assertSame('Alpha', $record->name);
        $this->assertTrue($record->is_active);

        $list = $this->withHeaders($headers)->getJson("/api/v1/{$this->scenarioARoute}/all/paginated");
        $list->assertStatus(200, $list->getContent());

        $this->withHeaders($headers)->postJson("/api/v1/{$this->scenarioARoute}/deactivate", ['id' => $record->id])->assertStatus(200);
        $this->assertFalse($record->fresh()->is_active);
        $this->assertNull($record->fresh()->deleted_at, 'deactivate must not soft-delete.');

        $this->withHeaders($headers)->postJson("/api/v1/{$this->scenarioARoute}/delete", ['id' => $record->id])->assertStatus(200);
        $this->assertNotNull($record->fresh()->deleted_at);
        $this->assertFalse($record->fresh()->is_active, 'soft-delete must not implicitly reactivate.');

        $record->fresh()->restore();
        $this->assertNull($record->fresh()->deleted_at);
    }

    /**
     * Scenario B: a complex module — belongsTo (DemoItem -> Category, a
     * real, pre-existing model), a single image attribute, a multiple
     * image attribute, opt-in stats, and --permissions-protected CRUD.
     * Exercises the full chain: relation FK persists and is eager-
     * loadable via ?include=, an image can be uploaded and downloaded,
     * stats reflect real data, and create/list are actually gated by the
     * generated policy (not just present in the routes file).
     */
    public function test_scenario_b_complex_module_relations_images_stats_and_permissions(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->scenarioB,
            '--fields' => 'title:string:required;price:decimal:8,2:required',
            '--relations' => 'category:belongsTo:Category',
            '--images' => 'cover:single;gallery:multiple:5',
            '--guard' => 'sanctum',
            '--permissions' => true,
            '--stats' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->scenarioBRoute}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        // --permissions requires an actual grant — unprivileged first.
        $deniedList = $this->withHeaders($headers)->getJson("/api/v1/{$this->scenarioBRoute}/all/paginated");
        $deniedList->assertStatus(403, $deniedList->getContent());
        $deniedCreate = $this->withHeaders($headers)->postJson("/api/v1/{$this->scenarioBRoute}/create", ['title' => 'Widget', 'price' => 9.99]);
        $deniedCreate->assertStatus(403, $deniedCreate->getContent());

        // Grant exactly the role the generated policy checks for
        // (snake_case module key, guard "admin" — the generated
        // policy's isManager() resolves the management guard's own
        // name, not "web") — then retry, proving the gate is real, not
        // just present.
        $roleName = 'manage-'.Str::snake($this->scenarioB);
        Role::findOrCreate($roleName, 'admin');
        $admin = Admin::factory()->create();
        $admin->assignRole($roleName);
        // Without RefreshDatabase (deliberately not used here — see this
        // file's own class doc comment / the established
        // MakeModuleCommandTest pattern), Spatie's permission cache isn't
        // automatically cleared by its own testing event listener, so a
        // role created and assigned mid-test can still read back stale
        // for the next Gate check unless forced to refresh.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $adminToken = $admin->createToken('t', ['admin'])->plainTextToken;
        $adminHeaders = ['Authorization' => "Bearer {$adminToken}"];

        // Illuminate\Auth\RequestGuard::user() caches the resolved user
        // for the guard instance's lifetime once resolved, and that
        // guard instance (cached inside AuthManager) outlives a single
        // simulated request in Laravel's own test harness — so the
        // $user-as-actor requests just above would otherwise poison
        // every LATER request in this same test method into resolving
        // as $user again, regardless of which token is actually sent. A
        // real production request never hits this (every real request
        // gets a fresh application boot) — found only by an end-to-end
        // test asserting on actor IDENTITY (not just response status)
        // after already authenticating as a different actor earlier in
        // the same test method.
        app('auth')->forgetGuards();

        $category = Category::create(['name' => 'Gear', 'slug' => 'gear-'.uniqid(), 'image' => 'x.png', 'description' => 'x']);

        $create = $this->withHeaders($adminHeaders)->postJson("/api/v1/{$this->scenarioBRoute}/create", [
            'title' => 'Widget',
            'price' => 9.99,
            'category_id' => $category->id,
        ]);
        $create->assertStatus(200, $create->getContent());

        $modelClass = "App\\Models\\{$this->scenarioB}";
        $record = $modelClass::first();
        $this->assertNotNull($record);
        $this->assertSame($category->id, $record->category_id);

        $listWithInclude = $this->withHeaders($adminHeaders)->getJson("/api/v1/{$this->scenarioBRoute}/all/paginated?include=category");
        $listWithInclude->assertStatus(200, $listWithInclude->getContent());
        $items = $listWithInclude->json('data.'.Str::plural(mb_strtolower($this->scenarioB)));
        $this->assertNotNull($items[0]['category'] ?? null, 'category relation should be present via ?include=: '.$listWithInclude->getContent());

        $upload = $this->withHeaders($headers)->post("/api/v1/{$this->scenarioBRoute}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('cover.jpg', 50, 50),
        ]);
        $upload->assertStatus(200, $upload->getContent());
        $coverPath = $upload->json('data.path');
        Storage::disk('local')->assertExists($coverPath);

        $download = $this->withHeaders($headers)->post("/api/v1/{$this->scenarioBRoute}/images/cover/download", ['id' => $record->id]);
        $download->assertStatus(200);

        $stats = $this->withHeaders($headers)->getJson("/api/v1/{$this->scenarioBRoute}/stats");
        $stats->assertStatus(200, $stats->getContent());
        $this->assertSame(1, $stats->json('data.stats.total'));
        $this->assertEqualsWithDelta(9.99, $stats->json('data.stats.sum_price'), 0.001);
    }

    /**
     * Scenario C: an auth-enabled module (`make:auth-module`, reused —
     * not reimplemented by this phase) — a real register -> verify
     * (fake OTP channel, no real SMS/email provider needed) -> login
     * flow against its OWN generated, guard-isolated routes.
     */
    public function test_scenario_c_auth_enabled_module_register_verify_login(): void
    {
        $this->artisan('make:auth-module', [
            'name' => $this->scenarioC,
            '--login' => 'email',
            '--registration' => true,
            '--otp' => true,
            '--enable-routes' => true,
        ])->assertExitCode(0);

        // The generator edits config/auth_modules.php on disk, but this
        // test's app was already booted (its config() repository already
        // loaded) before that write — force-reload just this namespace
        // from the now-updated file, the same workaround
        // MakeAuthModuleCommandTest's own otp-flow test already
        // established for exactly this reason.
        config(['auth_modules' => include $this->authModulesConfigPath]);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->scenarioCRoute}.php"));

        $email = 'e2e-member-'.uniqid().'@example.com';

        $register = $this->postJson("/api/v1/{$this->scenarioCRoute}/register", [
            'name' => 'Demo Member',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'otp_delivery_method' => 'fake',
        ]);
        $register->assertStatus(200, 'register: '.$register->getContent());

        $modelClass = "App\\Models\\{$this->scenarioC}";
        $account = $modelClass::where('email', $email)->first();
        $this->assertNotNull($account, 'register() did not create the account row.');

        $code = FakeOtpChannel::lastCodeFor($account, 'register');
        $this->assertNotNull($code, 'No OTP code was captured for the generated register() flow.');

        $verify = $this->postJson("/api/v1/{$this->scenarioCRoute}/register/verify", [
            'email' => $email,
            'otp' => $code,
            'purpose' => 'register',
        ]);
        $verify->assertStatus(200, 'verify: '.$verify->getContent());

        $login = $this->postJson("/api/v1/{$this->scenarioCRoute}/login", [
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
        ]);
        $login->assertStatus(200, 'login: '.$login->getContent());
        $this->assertNotEmpty($login->json('data.token') ?? $login->json('data.access_token'));
    }

    /**
     * Scenario D (Phase 11H): the fresh-project chain. `starter:new` is
     * exercised exactly as StarterNewProjectCommandTest already verifies
     * it (independent copy, no vendor/.env/uploads carried over) — not
     * re-asserted in detail here, only confirmed to still succeed as a
     * link in this larger chain. The generator -> docs -> diagnostic
     * pipeline (make:module --with-openapi -> openapi:build --postman ->
     * docs:check-drift -> starter:doctor) is then run for real, inside
     * this already-booted app, since the fresh copy has no vendor/ of
     * its own to run its own artisan commands against.
     */
    public function test_scenario_d_fresh_project_generation_docs_and_doctor_chain(): void
    {
        $this->scenarioDTarget = rtrim(sys_get_temp_dir(), '/\\').'/starter_e2e_fresh_'.uniqid();

        $this->artisan('starter:new', ['path' => $this->scenarioDTarget])->assertExitCode(0);
        $this->assertFileExists("{$this->scenarioDTarget}/composer.json");
        $this->assertFileExists("{$this->scenarioDTarget}/.starter-kit.json");
        $this->assertFileDoesNotExist("{$this->scenarioDTarget}/.env.backup");

        $this->artisan('make:module', [
            'name' => $this->scenarioD,
            '--fields' => 'label:string:required',
            '--guard' => 'sanctum',
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->scenarioDRoute}.php"));

        $this->artisan('openapi:build', ['--postman' => true])->assertExitCode(0);

        $document = Yaml::parseFile(base_path('docs/openapi/openapi.yaml'));
        $this->assertArrayHasKey("/api/v1/{$this->scenarioDRoute}/create", $document['paths']);
        $this->assertArrayHasKey($this->scenarioD, $document['components']['schemas']);
        $this->assertFileExists(base_path('docs/postman/collection.json'));
        $this->assertFileExists(base_path('docs/postman/environment.json'));

        Artisan::call('docs:check-drift');
        $this->assertStringNotContainsString(
            $this->scenarioDRoute,
            Artisan::output(),
            'docs:check-drift must not report drift for the module this test just generated and documented.'
        );

        $doctorExitCode = Artisan::call('starter:doctor');
        $doctorOutput = Artisan::output();
        $this->assertSame(0, $doctorExitCode, "starter:doctor reported a failure in a freshly-generated, fully-documented project:\n{$doctorOutput}");
        $this->assertStringContainsString('`make:module` is registered', $doctorOutput);
        $this->assertStringContainsString('`openapi:build` is registered', $doctorOutput);
        $this->assertStringContainsString('`starter:new` is registered', $doctorOutput);
    }
}
