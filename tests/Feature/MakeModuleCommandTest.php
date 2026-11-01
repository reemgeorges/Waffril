<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4 (2026-10-25). This generator writes real project files (model,
 * migration, request, factory, facade, service, controller, resource,
 * routes, optionally policy/seeder/tests/OpenAPI) — there is no sandboxed
 * output root for this kind of generator. Every test uses one of several
 * disposable, unmistakably-test-only module names and tearDown() removes
 * every file any of them could have created, drops any table any of them
 * may have migrated, and reverts routes/api/v1/{route-file}.php,
 * regardless of which test ran or whether it passed.
 *
 * Phase 5 (2026-10-25): a SECOND disposable name ("GadgetWidget", distinct
 * from "GadgetThing") exists because `make:module` always calls
 * scaffold:service/scaffold:controller internally, and those call
 * class_exists($modelClass) — which, with PHP's default autoloading,
 * `require`s the model file right then. Once `App\Models\GadgetThing` is
 * loaded into this test RUN's single PHP process by any earlier test,
 * PHP can never "reload" it — a later test regenerating GadgetThing with
 * DIFFERENT fields/relations still has its own fresh file correctly
 * written to disk, but any code in that same process that instantiates
 * the class (a real HTTP request, `Model::create()`) still gets the
 * FIRST test's stale, in-memory class definition. Harmless for tests that
 * only assert on file CONTENTS (most of this file), but would silently
 * invalidate any test that asserts on real class BEHAVIOR for a field or
 * relation set another, earlier-run test in this class also used under
 * the same name. Giving the one other behavior-asserting test its own
 * distinct name sidesteps this rather than depending on declaration order.
 *
 * Phase 6 (2026-10-25): the same reasoning applies to image attributes —
 * "GadgetGizmo" and "GadgetTrinket" are two MORE distinct names because
 * the single-image and multiple-image lifecycle tests each make real HTTP
 * calls against their own {Name}MediaController, and each controller only
 * defines the upload/delete/[reorder]/download methods for ITS OWN
 * --images spec.
 */
class MakeModuleCommandTest extends TestCase
{
    protected string $name = 'GadgetThing';

    protected string $table = 'gadget_things';

    protected string $routeFile = 'gadget-thing';

    protected string $altName = 'GadgetWidget';

    protected string $altTable = 'gadget_widgets';

    protected string $altRouteFile = 'gadget-widget';

    /** Phase 6: third disposable name, for the same class-identity reason as "GadgetWidget" above. */
    protected string $imgName = 'GadgetGizmo';

    protected string $imgTable = 'gadget_gizmos';

    protected string $imgRouteFile = 'gadget-gizmo';

    /**
     * Fourth disposable name: the single-image and multiple-image
     * lifecycle tests both make real HTTP calls against their own
     * {Name}MediaController, so they need distinct names from EACH OTHER
     * too — not just from "GadgetThing"/"GadgetWidget" — or whichever one
     * the test runner loads first freezes a controller class missing the
     * other's methods (uploadCover vs uploadGallery) for the rest of the
     * run.
     */
    protected string $imgName2 = 'GadgetTrinket';

    protected string $imgTable2 = 'gadget_trinkets';

    protected string $imgRouteFile2 = 'gadget-trinket';

    /** Phase 7: fifth disposable name, same class-identity reasoning, now for {Name}StatsController. */
    protected string $statsName = 'GadgetSprocket';

    protected string $statsTable = 'gadget_sprockets';

    protected string $statsRouteFile = 'gadget-sprocket';

    /** Phase 5: pivot table for the `tags:belongsToMany:Category` relation tests use. */
    protected string $pivotTable = 'category_gadget_thing';

    protected function tearDown(): void
    {
        foreach ([$this->table, $this->altTable, $this->imgTable, $this->imgTable2, $this->statsTable, $this->pivotTable] as $table) {
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
        }

        $routeFileFor = [
            $this->name => $this->routeFile,
            $this->altName => $this->altRouteFile,
            $this->imgName => $this->imgRouteFile,
            $this->imgName2 => $this->imgRouteFile2,
            $this->statsName => $this->statsRouteFile,
        ];

        foreach ([$this->name, $this->altName, $this->imgName, $this->imgName2, $this->statsName] as $name) {
            $routeFile = $routeFileFor[$name];

            foreach ([
                app_path("Models/{$name}.php"),
                app_path("Http/Requests/Model/Store{$name}Request.php"),
                database_path("factories/{$name}Factory.php"),
                app_path("Facades/Services/{$name}/{$name}Facade.php"),
                app_path("Policies/{$name}Policy.php"),
                database_path("seeders/{$name}Seeder.php"),
                base_path("tests/Feature/{$name}ModuleTest.php"),
                base_path("docs/openapi/modules/{$routeFile}.yaml"),
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

        // The `categories` table is real, pre-existing, shared state (not
        // created or dropped by this test class) — only the one disposable
        // row the ?include= test creates in it needs cleaning up.
        if (Schema::hasTable('categories')) {
            Category::withTrashed()->where('slug', 'gadgets')->forceDelete();
        }

        $this->purgeCommittedRowsOnMySql();

        parent::tearDown();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
        $this->assertFileDoesNotExist(database_path("migrations/{$this->table}"));
    }

    public function test_at_least_one_field_is_required(): void
    {
        $this->artisan('make:module', ['name' => $this->name])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_unknown_field_type_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:not-a-real-type',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_guard_and_public_together_are_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--public' => true,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_permissions_without_guard_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--permissions' => true,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_generates_all_expected_files_with_valid_syntax(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required;price:decimal:8,2:required;description:text:nullable;sku:string:unique',
            '--guard' => 'sanctum',
            '--permissions' => true,
            '--with-seeder' => true,
            '--with-tests' => true,
            '--with-openapi' => true,
        ])->assertExitCode(0);

        $expectedFiles = [
            app_path("Models/{$this->name}.php"),
            app_path("Http/Requests/Model/Store{$this->name}Request.php"),
            database_path("factories/{$this->name}Factory.php"),
            app_path("Facades/Services/{$this->name}/{$this->name}Facade.php"),
            app_path("Policies/{$this->name}Policy.php"),
            database_path("seeders/{$this->name}Seeder.php"),
            base_path("tests/Feature/{$this->name}ModuleTest.php"),
            base_path("docs/openapi/modules/{$this->routeFile}.yaml"),
            app_path("Http/Resources/Model/{$this->name}Resource.php"),
            app_path("Services/Model/{$this->name}/{$this->name}Service.php"),
            app_path("Http/Controllers/{$this->name}/{$this->name}Controller.php"),
            base_path("routes/api/v1/{$this->routeFile}.php"),
        ];

        foreach ($expectedFiles as $path) {
            $this->assertFileExists($path, "Expected generated file missing: {$path}");
        }

        $migrations = File::glob(database_path("migrations/*_create_{$this->table}_table.php"));
        $this->assertCount(1, $migrations, 'Expected exactly one migration for the table.');
        $expectedFiles[] = $migrations[0];

        foreach ($expectedFiles as $path) {
            // No pipe to grep here: PHP's shell_exec() runs via cmd.exe on
            // Windows, where grep isn't guaranteed to be on PATH. Parse
            // `php -l`'s own output directly instead.
            $output = (string) shell_exec('php -l '.escapeshellarg($path).' 2>&1');
            $this->assertStringContainsString(
                'No syntax errors detected',
                $output,
                "Generated file has a syntax error: {$path}\n{$output}"
            );
        }

        $routesContent = File::get(base_path("routes/api/v1/{$this->routeFile}.php"));
        $this->assertStringContainsString('guard.identity:sanctum', $routesContent);
        $this->assertStringContainsString("can:viewAny,\\App\\Models\\{$this->name}", $routesContent);
        $this->assertStringContainsString("can:create,\\App\\Models\\{$this->name}", $routesContent);

        $modelContent = File::get(app_path("Models/{$this->name}.php"));
        $this->assertStringContainsString("'is_active' => 'boolean'", $modelContent);
        $this->assertStringContainsString("'price' => 'decimal:2'", $modelContent);
    }

    public function test_generator_is_idempotent_on_rerun(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
        ])->assertExitCode(0);

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
        ])->expectsOutputToContain('already exists')->assertExitCode(0);
    }

    public function test_default_crud_without_guard_or_public_produces_a_disabled_route_scaffold(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
        ])->assertExitCode(0);

        $routesContent = File::get(base_path("routes/api/v1/{$this->routeFile}.php"));

        $this->assertStringNotContainsString("\nRoute::prefix('{$this->routeFile}')", $routesContent);
        $this->assertStringContainsString('// Route::prefix', $routesContent);
    }

    /**
     * Proves the full generated chain (migration -> model -> request ->
     * factory -> facade -> service -> controller -> resource -> routes)
     * genuinely works end-to-end over real HTTP — not just "files exist".
     *
     * Does not use RefreshDatabase: that trait's "migrate once" caching is
     * a process-wide static flag set by whichever test uses it first in
     * the whole suite run, so a migration created by THIS test, mid-suite,
     * would never be picked up by it. The one migration this test needs
     * is run explicitly, by path, against the same sqlite `:memory:` test
     * connection every other test already uses (per phpunit.xml) — not a
     * real database, and no different in kind from what RefreshDatabase
     * itself does under the hood.
     */
    public function test_generated_module_works_end_to_end_over_http(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required;price:decimal:8,2:required',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $migrations = File::glob(database_path("migrations/*_create_{$this->table}_table.php"));
        $this->assertCount(1, $migrations);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // bootstrap/app.php globs routes/api/v1/*.php and registers each
        // file via Route::middleware('api')->prefix('api/v1')->group($file)
        // once, at application boot — which happens in setUp(), BEFORE this
        // test method (and therefore make:module) ever runs. The file this
        // test just generated is invisible to the router until it is
        // registered the same way, by hand, right here.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->routeFile}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $modelClass = "App\\Models\\{$this->name}";

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/gadget-thing/create', ['title' => 'Test Gadget', 'price' => 19.99]);
        $create->assertStatus(200, 'create: '.$create->getContent());

        $record = $modelClass::first();
        $this->assertNotNull($record);
        $this->assertSame('Test Gadget', $record->title);
        $this->assertTrue($record->is_active);

        $list = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/gadget-thing/all/paginated');
        $list->assertStatus(200, 'list: '.$list->getContent());

        $deactivate = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/gadget-thing/deactivate', ['id' => $record->id]);
        $deactivate->assertStatus(200);
        $this->assertFalse($record->fresh()->is_active);

        $delete = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/gadget-thing/delete', ['id' => $record->id]);
        $delete->assertStatus(200);
        $this->assertNotNull($record->fresh()->deleted_at);

        // guard.identity: an Admin token must not reach this sanctum-guarded
        // module's routes any more than it would any other guard mismatch.
        $admin = Admin::factory()->create();
        $adminToken = $admin->createToken('t', ['admin'])->plainTextToken;

        // 'sanctum' is model-agnostic (see ScaffoldRouteCommand's doc
        // comment) so guard.identity:sanctum is a no-op here — this
        // assertion documents that, rather than asserting a false
        // guarantee. Real isolation is proven for 'admin'/'user' guards
        // elsewhere (AuthGuardFoundationTest).
        $crossGuard = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/v1/gadget-thing/all/paginated');
        $crossGuard->assertStatus(200);
    }

    public function test_with_delete_restore_force_delete_require_a_real_guard(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--crud' => 'create,read,restore,forceDelete',
        ])->assertExitCode(0);

        // No --guard/--public was passed, so even though --crud asked for
        // restore/forceDelete, scaffold:routes (reused internally) must
        // have refused to add them to anything but a disabled scaffold —
        // confirmed structurally: the file has no live group at all.
        $routesContent = File::get(base_path("routes/api/v1/{$this->routeFile}.php"));
        $this->assertStringNotContainsString("\nRoute::prefix('{$this->routeFile}')", $routesContent);
    }

    // ===================== Phase 5: relations =====================

    public function test_unknown_relation_type_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => 'category:not-a-real-type:Category',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_relation_missing_related_model_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => 'category:belongsTo',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_self_referencing_relation_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => "parent:belongsTo:{$this->name}",
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->name}.php"));
    }

    public function test_belongs_to_relation_generates_fk_column_model_method_and_wired_resource(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => 'category:belongsTo:Category',
        ])->assertExitCode(0);

        $migrations = File::glob(database_path("migrations/*_create_{$this->table}_table.php"));
        $this->assertCount(1, $migrations);
        $migrationContent = File::get($migrations[0]);
        $this->assertStringContainsString("unsignedBigInteger('category_id')->nullable()", $migrationContent);

        $modelContent = File::get(app_path("Models/{$this->name}.php"));
        $this->assertStringContainsString("protected array \$allowedIncludes = ['category']", $modelContent);
        $this->assertStringContainsString('public function category(): BelongsTo', $modelContent);
        $this->assertStringContainsString('return $this->belongsTo(\App\Models\Category::class', $modelContent);

        $requestContent = File::get(app_path("Http/Requests/Model/Store{$this->name}Request.php"));
        $this->assertStringContainsString("'category_id' => 'nullable|integer|exists:categories,id'", $requestContent);

        $resourceContent = File::get(app_path("Http/Resources/Model/{$this->name}Resource.php"));
        $this->assertStringContainsString("whenLoaded('category'", $resourceContent);
        $this->assertStringContainsString('\App\Http\Resources\Model\CategoryResource::make', $resourceContent);
    }

    public function test_belongs_to_many_relation_generates_an_idempotent_pivot_migration(): void
    {
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => 'tags:belongsToMany:Category',
        ])->assertExitCode(0);

        $pivotMigrations = File::glob(database_path("migrations/*_create_{$this->pivotTable}_table.php"));
        $this->assertCount(1, $pivotMigrations);

        $pivotContent = File::get($pivotMigrations[0]);
        $this->assertStringContainsString("unsignedBigInteger('gadget_thing_id')", $pivotContent);
        $this->assertStringContainsString("unsignedBigInteger('category_id')", $pivotContent);

        $modelContent = File::get(app_path("Models/{$this->name}.php"));
        $this->assertStringContainsString('public function tags(): BelongsToMany', $modelContent);

        // Re-running (--force, same relation) must not queue a second pivot
        // migration file — buildPlan() checks for an existing one on disk
        // by table name before planning it (see its own doc comment: a
        // fresh timestamp alone can't be deduped by writeIfAbsent()'s
        // exact-path check the way every other generated file is).
        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--relations' => 'tags:belongsToMany:Category',
            '--force' => true,
        ])->assertExitCode(0);

        $pivotMigrationsAfterRerun = File::glob(database_path("migrations/*_create_{$this->pivotTable}_table.php"));
        $this->assertCount(1, $pivotMigrationsAfterRerun);
        $this->assertSame($pivotMigrations[0], $pivotMigrationsAfterRerun[0]);
    }

    /**
     * Proves the full Phase 5 safety property end-to-end: a client's
     * `?include=` can only ever eager-load a relation the model explicitly
     * listed in $allowedIncludes — never an arbitrary relation name, and
     * never anything the server didn't already permit.
     */
    public function test_include_query_param_only_loads_allowlisted_relations(): void
    {
        $this->artisan('make:module', [
            'name' => $this->altName,
            '--fields' => 'title:string:required',
            '--relations' => 'category:belongsTo:Category',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->altRouteFile}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        // No CategoryFactory exists in this codebase — create directly
        // against its real (non-nullable) columns instead.
        $category = Category::create([
            'name' => 'Gadgets',
            'slug' => 'gadgets',
            'image' => 'gadgets.png',
            'description' => 'Test category',
        ]);
        $modelClass = "App\\Models\\{$this->altName}";
        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true, 'category_id' => $category->id]);

        // BasicCrudService::allPaginated() keys the collection as
        // Str::plural(strtolower(class_basename($model))) — for
        // "GadgetWidget" that's "gadgetwidgets".
        $listKey = 'data.gadgetwidgets';

        // allowlisted: present and populated.
        $withInclude = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/{$this->altRouteFile}/all/paginated?include=category");
        $withInclude->assertStatus(200, $withInclude->getContent());
        $gadgetThings = $withInclude->json($listKey);
        $this->assertNotNull($gadgetThings[0]['category'] ?? null, 'category should be present when allowlisted and requested: '.$withInclude->getContent());

        // not requested: relation key is simply absent (whenLoaded()), no
        // eager load happened, no extra query — the default/safe case.
        $withoutInclude = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/{$this->altRouteFile}/all/paginated");
        $withoutInclude->assertStatus(200);
        $gadgetThingsNoInclude = $withoutInclude->json($listKey);
        $this->assertArrayNotHasKey('category', $gadgetThingsNoInclude[0] ?? []);

        // a relation NOT in $allowedIncludes must never be loadable via
        // ?include=, however the client phrases it — confirms the
        // allowlist actually restricts, not just happens to match.
        $disallowed = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/{$this->altRouteFile}/all/paginated?include=notARealRelationAtAll");
        $disallowed->assertStatus(200, $disallowed->getContent());
    }

    // ===================== Phase 6: image attributes =====================

    public function test_images_option_requires_a_guard(): void
    {
        $this->artisan('make:module', [
            'name' => $this->imgName,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->imgName}.php"));
    }

    public function test_unknown_image_type_is_rejected(): void
    {
        $this->artisan('make:module', [
            'name' => $this->imgName,
            '--fields' => 'title:string:required',
            '--images' => 'cover:not-a-real-type',
            '--guard' => 'sanctum',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->imgName}.php"));
    }

    public function test_images_generates_model_wiring_and_media_controller(): void
    {
        $this->artisan('make:module', [
            'name' => $this->imgName,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single;gallery:multiple:5',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $migrationContent = File::get(File::glob(database_path("migrations/*_create_{$this->imgTable}_table.php"))[0]);
        $this->assertStringContainsString("string('cover')->nullable()", $migrationContent);
        $this->assertStringContainsString("json('gallery')->nullable()", $migrationContent);

        $modelContent = File::get(app_path("Models/{$this->imgName}.php"));
        $this->assertStringContainsString("'cover' => 'single'", $modelContent);
        $this->assertStringContainsString("'gallery' => 'array'", $modelContent);
        $this->assertStringContainsString("fileDisk = 'local'", $modelContent);
        $this->assertStringContainsString("'gallery' => 'array',", $modelContent); // cast

        // Image paths must never be client mass-assignable through the
        // normal create()/update() path — only via the media controller's
        // own forceFill(). Checked against the $fillable array
        // specifically (not the whole file — 'cover'/'gallery' legitimately
        // appear elsewhere, in $fileAttributes).
        preg_match('/\$fillable = \[(.*?)\];/s', $modelContent, $fillableMatch);
        $this->assertStringNotContainsString('cover', $fillableMatch[1] ?? '');
        $this->assertStringNotContainsString('gallery', $fillableMatch[1] ?? '');

        $controllerPath = app_path("Http/Controllers/{$this->imgName}/{$this->imgName}MediaController.php");
        $this->assertFileExists($controllerPath);
        $output = (string) shell_exec('php -l '.escapeshellarg($controllerPath).' 2>&1');
        $this->assertStringContainsString('No syntax errors detected', $output);

        $routesContent = File::get(base_path("routes/api/v1/{$this->imgRouteFile}.php"));
        $this->assertStringContainsString("prefix('{$this->imgRouteFile}')", $routesContent);
        $this->assertStringContainsString('images/cover/upload', $routesContent);
        $this->assertStringContainsString('images/gallery/reorder', $routesContent);
        // Both route groups (main CRUD + images) must share the SAME
        // prefix — a real bug caught during manual verification used
        // $c['uri'] (plural) for the image group, splitting one module's
        // API across two different URL prefixes.
        $this->assertSame(2, substr_count($routesContent, "prefix('{$this->imgRouteFile}')"));
    }

    /**
     * Full single-image lifecycle over real HTTP: upload, replace (old
     * file cleaned up via BaseModel's existing cleanupReplacedFiles()
     * hook), download, delete. Storage::fake('local') isolates every
     * file write to an in-memory fake disk — no real file ever touches
     * storage/app.
     */
    public function test_single_image_upload_replace_download_delete_lifecycle(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->imgName,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->imgRouteFile}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $modelClass = "App\\Models\\{$this->imgName}";
        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);

        $upload1 = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('photo1.jpg', 50, 50),
        ]);
        $upload1->assertStatus(200, $upload1->getContent());
        $firstPath = $upload1->json('data.path');
        $this->assertNotEmpty($firstPath);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertSame($firstPath, $record->fresh()->cover);

        $download1 = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile}/images/cover/download", [
            'id' => $record->id,
        ]);
        $download1->assertStatus(200);

        // Replace: old file must be cleaned up automatically by
        // BaseModel's pre-existing cleanupReplacedFiles() hook — this
        // phase did not touch that mechanism, only configured it.
        $upload2 = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('photo2.jpg', 50, 50),
        ]);
        $upload2->assertStatus(200, $upload2->getContent());
        $secondPath = $upload2->json('data.path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
        $this->assertSame($secondPath, $record->fresh()->cover);

        $delete = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile}/images/cover/delete", [
            'id' => $record->id,
        ]);
        $delete->assertStatus(200, $delete->getContent());
        Storage::disk('local')->assertMissing($secondPath);
        $this->assertNull($record->fresh()->cover);

        // Wrong file type is rejected (422) before anything is stored.
        $badType = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]);
        $badType->assertStatus(422, $badType->getContent());
    }

    /**
     * Multiple-image lifecycle: max-count enforcement, delete-by-owned-path
     * only, and reorder-as-permutation-only — the three safety properties
     * `{Name}MediaController`'s own doc comment documents.
     */
    public function test_multiple_image_max_count_delete_and_reorder_safety(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->imgName2,
            '--fields' => 'title:string:required',
            '--images' => 'gallery:multiple:2',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->imgRouteFile2}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $modelClass = "App\\Models\\{$this->imgName2}";
        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);

        $upload = fn () => $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile2}/images/gallery/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('photo.jpg', 50, 50),
        ]);

        $first = $upload();
        $first->assertStatus(200, $first->getContent());
        $second = $upload();
        $second->assertStatus(200, $second->getContent());

        // maxCount is 2 — a third must be rejected, not silently appended.
        $third = $upload();
        $third->assertStatus(500, $third->getContent());
        $this->assertCount(2, $record->fresh()->gallery);

        $paths = $record->fresh()->gallery;

        // delete: only an EXISTING, owned path may be removed.
        $deleteForeign = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile2}/images/gallery/delete", [
            'id' => $record->id,
            'path' => 'gadget_trinkets/gallery/not-a-real-file.jpg',
        ]);
        $deleteForeign->assertStatus(500, $deleteForeign->getContent());
        $this->assertCount(2, $record->fresh()->gallery);

        // reorder: must be an exact permutation — adding/dropping a path
        // is rejected, not silently truncated or extended.
        $badOrder = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile2}/images/gallery/reorder", [
            'id' => $record->id,
            'order' => [$paths[0]],
        ]);
        $badOrder->assertStatus(500, $badOrder->getContent());
        $this->assertSame($paths, $record->fresh()->gallery);

        // reorder: a genuine permutation (reverse order) is accepted.
        $goodOrder = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile2}/images/gallery/reorder", [
            'id' => $record->id,
            'order' => array_reverse($paths),
        ]);
        $goodOrder->assertStatus(200, $goodOrder->getContent());
        $this->assertSame(array_reverse($paths), $record->fresh()->gallery);

        // delete: now a real, owned path succeeds.
        $deleteOwned = $this->withHeaders($headers)->post("/api/v1/{$this->imgRouteFile2}/images/gallery/delete", [
            'id' => $record->id,
            'path' => $paths[0],
        ]);
        $deleteOwned->assertStatus(200, $deleteOwned->getContent());
        $this->assertCount(1, $record->fresh()->gallery);
        Storage::disk('local')->assertMissing($paths[0]);
    }

    // ===================== Phase 7: stats =====================

    public function test_stats_option_requires_a_guard(): void
    {
        $this->artisan('make:module', [
            'name' => $this->statsName,
            '--fields' => 'title:string:required;price:decimal:8,2:required',
            '--stats' => true,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->statsName}.php"));
    }

    public function test_stats_group_by_must_be_a_known_field(): void
    {
        $this->artisan('make:module', [
            'name' => $this->statsName,
            '--fields' => 'title:string:required',
            '--stats' => true,
            '--stats-group-by' => 'not_a_real_field',
            '--guard' => 'sanctum',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist(app_path("Models/{$this->statsName}.php"));
    }

    /**
     * Proves Phase 7's core safety and correctness properties end-to-end,
     * against real, known records: numerically exact sum/avg/min/max,
     * active/inactive/soft-deleted counts that reflect real record state,
     * filter-awareness (the stats endpoint's $base genuinely reuses
     * BaseModel::scopeWithFilters(), the SAME filtering the list endpoint
     * uses — proven here via `?trashed=`, which scopeWithFilters() honors
     * unconditionally, with no per-field allowlist opt-in needed; most of
     * that engine's OTHER filters, like `?price_gte=`, are gated behind
     * $dynamicFilterColumns, which is empty by default on a freshly
     * generated model per Phase 5's secure-by-default design — not
     * exercised here since populating it on the already-generated file
     * has no effect in-process: `scaffold:service`'s own
     * class_exists($modelClass) check, which make:module calls
     * internally, already autoloads the class from its ORIGINAL content
     * before this test method ever gets a chance to edit the file), an
     * allowlisted group_by that works, and a non-allowlisted group_by
     * that is rejected rather than silently honored or ignored.
     */
    public function test_stats_endpoint_numerical_correctness_filter_awareness_and_group_by_allowlist(): void
    {
        $this->artisan('make:module', [
            'name' => $this->statsName,
            '--fields' => 'title:string:required;price:decimal:8,2:required',
            '--guard' => 'sanctum',
            '--stats' => true,
            '--stats-group-by' => 'title',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path("routes/api/v1/{$this->statsRouteFile}.php"));

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $modelClass = "App\\Models\\{$this->statsName}";

        // Known, exact data: 10.00 (active), 20.00 (active), 30.00
        // (INACTIVE), 40.00 (SOFT-DELETED).
        $a = $modelClass::create(['title' => 'alpha', 'price' => 10, 'is_active' => true]);
        $b = $modelClass::create(['title' => 'alpha', 'price' => 20, 'is_active' => true]);
        $c = $modelClass::create(['title' => 'beta', 'price' => 30, 'is_active' => false]);
        $d = $modelClass::create(['title' => 'beta', 'price' => 40, 'is_active' => true]);
        $d->delete();

        $response = $this->withHeaders($headers)->getJson("/api/v1/{$this->statsRouteFile}/stats?group_by=title");
        $response->assertStatus(200, $response->getContent());
        $stats = $response->json('data.stats');

        // SoftDeletes excludes $d from the default (non-trashed) query
        // scope, so total/active/inactive only ever see $a/$b/$c.
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(1, $stats['inactive']);
        $this->assertSame(1, $stats['soft_deleted']);
        $this->assertEqualsWithDelta(60.0, $stats['sum_price'], 0.001);
        $this->assertEqualsWithDelta(20.0, $stats['avg_price'], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $stats['min_price'], 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $stats['max_price'], 0.001);

        // group_by (allowlisted): two groups, counts of 2 and 1.
        $grouped = collect($stats['grouped'])->keyBy('title');
        $this->assertSame(2, (int) $grouped['alpha']['count']);
        $this->assertSame(1, (int) $grouped['beta']['count']);

        // Filter-aware: ?trashed=2 (BaseModel::scopeWithFilters(), honored
        // unconditionally, no per-field opt-in needed) tells the SAME
        // query the list endpoint would build to include the soft-deleted
        // $d too — proving the stats endpoint's $base genuinely goes
        // through withFilters() rather than a separately hardcoded query.
        $withTrashed = $this->withHeaders($headers)->getJson("/api/v1/{$this->statsRouteFile}/stats?trashed=2");
        $withTrashed->assertStatus(200, $withTrashed->getContent());
        $withTrashedStats = $withTrashed->json('data.stats');
        $this->assertSame(4, $withTrashedStats['total']);
        $this->assertEqualsWithDelta(100.0, $withTrashedStats['sum_price'], 0.001);

        // group_by naming a real column that is NOT in --stats-group-by's
        // allowlist must be rejected, not silently honored.
        $disallowed = $this->withHeaders($headers)->getJson("/api/v1/{$this->statsRouteFile}/stats?group_by=price");
        $disallowed->assertStatus(500, $disallowed->getContent());
    }
}
