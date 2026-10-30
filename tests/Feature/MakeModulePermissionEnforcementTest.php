<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11C (2026-10-26): previously `--permissions` only ever wired
 * can: middleware onto the list/create routes — update, delete,
 * activate, deactivate, restore, forceDelete, show, image operations,
 * and stats all had generated Policy methods that nothing ever
 * consulted. This proves the closed gap end-to-end over real HTTP: an
 * unprivileged actor is denied every one of those actions, and an actor
 * holding the exact `manage-{key}` role the generated policy checks for
 * is allowed — not just that the policy/controller files exist.
 *
 * Two disposable module names (for the same PHP class-identity reason
 * documented at length in MakeModuleCommandTest's class doc comment):
 * one for the role-based enforcement sweep, a separate one for the
 * `--owner-column` ownership-based path, since the two use different
 * --permissions configurations.
 */
class MakeModulePermissionEnforcementTest extends TestCase
{
    protected string $name = 'PermGadget';

    protected string $table = 'perm_gadgets';

    protected string $routeFile = 'perm-gadget';

    protected string $ownerName = 'PermOwnedGadget';

    protected string $ownerTable = 'perm_owned_gadgets';

    protected string $ownerRouteFile = 'perm-owned-gadget';

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

    protected function tearDown(): void
    {
        $this->removeModule($this->name, $this->table, $this->routeFile);
        $this->removeModule($this->ownerName, $this->ownerTable, $this->ownerRouteFile);

        $this->purgeCommittedRowsOnMySql();

        parent::tearDown();
    }

    /**
     * One comprehensive sweep rather than one test per ability: every
     * action shares the same generation/migration/role-grant setup, and
     * splitting it into N separate tests would mean regenerating the
     * whole module N times for no real isolation benefit — each
     * assertion below is independent and would fail on its own line if
     * the authorization for that specific action were missing or wrong.
     */
    public function test_every_instance_action_is_denied_without_the_role_and_allowed_with_it(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--guard' => 'sanctum',
            '--crud' => 'create,read,update,delete,activate,deactivate,restore,forceDelete',
            '--permissions' => true,
            '--images' => 'cover:single',
            '--stats' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->routeFile}.php"));

        $modelClass = "App\\Models\\{$this->name}";
        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);

        $outsider = User::factory()->create(['is_active' => true]);
        $outsiderToken = $outsider->createToken('t')->plainTextToken;
        $outsiderHeaders = ['Authorization' => "Bearer {$outsiderToken}"];

        // --- Denied: an authenticated actor with NO role at all. ---
        $this->withHeaders($outsiderHeaders)->getJson("/api/v1/{$this->routeFile}/all/paginated")->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/create", ['title' => 'x'])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/show", ['id' => $record->id])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/update", ['id' => $record->id, 'title' => 'y'])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/activate", ['id' => $record->id])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/deactivate", ['id' => $record->id])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/delete", ['id' => $record->id])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->postJson("/api/v1/{$this->routeFile}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('cover.jpg', 5, 5),
        ])->assertStatus(403);
        $this->withHeaders($outsiderHeaders)->getJson("/api/v1/{$this->routeFile}/stats")->assertStatus(403);

        // Nothing above actually changed anything.
        $this->assertSame('Gizmo', $record->fresh()->title);
        $this->assertTrue($record->fresh()->is_active);
        $this->assertNull($record->fresh()->deleted_at);

        // --- Allowed: an actor granted the exact role the policy checks for. ---
        // make:module snake_cases the module name for the role key —
        // computed the same way the generator itself does, not guessed.
        $roleName = 'manage-'.Str::snake($this->name);
        Role::findOrCreate($roleName, 'admin');
        $manager = Admin::factory()->create();
        $manager->assignRole($roleName);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $managerToken = $manager->createToken('t', ['admin'])->plainTextToken;
        $managerHeaders = ['Authorization' => "Bearer {$managerToken}"];

        // Illuminate\Auth\RequestGuard caches the resolved user for the
        // guard instance's lifetime (Phase 10 finding) — the earlier
        // $outsider requests above would otherwise poison every one of
        // these into still resolving as $outsider.
        app('auth')->forgetGuards();

        $this->withHeaders($managerHeaders)->getJson("/api/v1/{$this->routeFile}/all/paginated")->assertStatus(200);
        $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/show", ['id' => $record->id])->assertStatus(200);

        $update = $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/update", ['id' => $record->id, 'title' => 'Updated']);
        $update->assertStatus(200, $update->getContent());
        $this->assertSame('Updated', $record->fresh()->title);

        $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/deactivate", ['id' => $record->id])->assertStatus(200);
        $this->assertFalse($record->fresh()->is_active);

        $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/activate", ['id' => $record->id])->assertStatus(200);
        $this->assertTrue($record->fresh()->is_active);

        $upload = $this->withHeaders($managerHeaders)->post("/api/v1/{$this->routeFile}/images/cover/upload", [
            'id' => $record->id,
            'file' => UploadedFile::fake()->image('cover.jpg', 5, 5),
        ]);
        $upload->assertStatus(200, $upload->getContent());

        $stats = $this->withHeaders($managerHeaders)->getJson("/api/v1/{$this->routeFile}/stats");
        $stats->assertStatus(200, $stats->getContent());
        $this->assertSame(1, $stats->json('data.stats.total'));

        $delete = $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/delete", ['id' => $record->id]);
        $delete->assertStatus(200, $delete->getContent());
        $this->assertNotNull($record->fresh()->deleted_at);

        $restore = $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/restore", ['id' => $record->id]);
        $restore->assertStatus(200, $restore->getContent());
        $this->assertNull($record->fresh()->deleted_at);

        // forceDelete's generated policy method always returns false —
        // a deliberate, higher bar than regular management access, not
        // a bug — a manager role must still be denied here.
        $record->delete();
        $forceDelete = $this->withHeaders($managerHeaders)->postJson("/api/v1/{$this->routeFile}/force-delete", ['id' => $record->id]);
        $forceDelete->assertStatus(403, $forceDelete->getContent());
        $this->assertNotNull($modelClass::withTrashed()->find($record->id), 'forceDelete must still be denied by default even for a manager.');
    }

    public function test_owner_column_allows_the_records_own_owner_without_the_manager_role(): void
    {
        $this->artisan('make:module', [
            'name' => $this->ownerName,
            '--fields' => 'title:string:required',
            '--relations' => 'owner:belongsTo:User',
            '--guard' => 'sanctum',
            '--permissions' => true,
            '--owner-column' => 'owner_id',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path("routes/api/v1/{$this->ownerRouteFile}.php"));

        $modelClass = "App\\Models\\{$this->ownerName}";

        $owner = User::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['is_active' => true]);

        $record = $modelClass::create(['title' => 'Mine', 'is_active' => true, 'owner_id' => $owner->id]);

        $ownerToken = $owner->createToken('t')->plainTextToken;
        $strangerToken = $stranger->createToken('t')->plainTextToken;

        // The owner has no manage-role at all — only the ownership match
        // grants access.
        $asOwner = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/v1/{$this->ownerRouteFile}/show", ['id' => $record->id]);
        $asOwner->assertStatus(200, $asOwner->getContent());

        $asOwnerUpdate = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/v1/{$this->ownerRouteFile}/update", ['id' => $record->id, 'title' => 'Mine, updated']);
        $asOwnerUpdate->assertStatus(200, $asOwnerUpdate->getContent());
        $this->assertSame('Mine, updated', $record->fresh()->title);

        // A different, unrelated user — not the owner, not a manager —
        // must still be denied.
        app('auth')->forgetGuards();
        $asStranger = $this->withHeader('Authorization', "Bearer {$strangerToken}")
            ->postJson("/api/v1/{$this->ownerRouteFile}/show", ['id' => $record->id]);
        $asStranger->assertStatus(403, $asStranger->getContent());
    }
}
