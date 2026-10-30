<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 11B (2026-10-26) — regression test for a real bug in
 * BaseModel::cleanupReplacedFiles()/cleanupAllFiles(): both ran
 * synchronously on the `updating`/`deleting`/`forceDeleted` model
 * events, which fire before the actual SQL statement commits. If the
 * save() happened inside a caller's DB::transaction() that later rolled
 * back for an unrelated reason, the OLD file was already deleted from
 * disk by the time the rollback undid the DB row back to pointing at
 * that same (now-missing) path. Fixed by deferring every actual
 * deletion to DB::afterCommit().
 *
 * Uses a disposable `make:module`-generated single-image model (its own
 * distinct name, for the same PHP class-identity reason documented at
 * length in MakeModuleCommandTest's class doc comment) and
 * Storage::fake('local') so no real file is ever touched.
 */
class BaseModelFileCleanupTransactionSafetyTest extends TestCase
{
    protected string $name = 'TxSafetyWidget';

    protected string $table = 'tx_safety_widgets';

    protected function tearDown(): void
    {
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
            app_path("Http/Requests/Model/Store{$this->name}Request.php"),
            database_path("factories/{$this->name}Factory.php"),
            app_path("Facades/Services/{$this->name}/{$this->name}Facade.php"),
            app_path("Http/Resources/Model/{$this->name}Resource.php"),
            app_path("Http/Controllers/{$this->name}/{$this->name}Controller.php"),
            app_path("Http/Controllers/{$this->name}/{$this->name}MediaController.php"),
            base_path('routes/api/v1/tx-safety-widget.php'),
        ] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        foreach ([
            app_path("Services/Model/{$this->name}"),
            app_path("Facades/Services/{$this->name}"),
            app_path("Http/Controllers/{$this->name}"),
        ] as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        $this->purgeCommittedRowsOnMySql();

        parent::tearDown();
    }

    public function test_replaced_file_is_not_deleted_when_the_enclosing_transaction_rolls_back(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $modelClass = "App\\Models\\{$this->name}";

        $oldPath = "{$this->table}/cover/old.jpg";
        Storage::disk('local')->put($oldPath, 'old-content');

        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);
        $record->forceFill(['cover' => $oldPath])->save();
        Storage::disk('local')->assertExists($oldPath);

        $newPath = "{$this->table}/cover/new.jpg";
        Storage::disk('local')->put($newPath, 'new-content');

        try {
            DB::transaction(function () use ($record, $newPath) {
                $record->forceFill(['cover' => $newPath])->save();

                // Something else in the same unit of work fails — the
                // whole transaction, including this cover change, must
                // roll back.
                throw new \RuntimeException('simulated failure after save()');
            });
            $this->fail('Expected the transaction to roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure after save()', $e->getMessage());
        }

        // DB rolled back: the row still points at the OLD path.
        $this->assertSame($oldPath, $record->fresh()->cover);

        // The real bug this test guards against: the OLD file must
        // still exist — it was never actually "replaced", since the
        // transaction that would have made that true never committed.
        // Note: assertExists()'s 2nd argument is expected file CONTENT,
        // not a message — the old file must survive a rolled-back
        // transaction, which is exactly what this asserts.
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_replaced_file_is_deleted_normally_when_the_update_actually_commits(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $modelClass = "App\\Models\\{$this->name}";

        $oldPath = "{$this->table}/cover/old.jpg";
        Storage::disk('local')->put($oldPath, 'old-content');

        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);
        $record->forceFill(['cover' => $oldPath])->save();

        $newPath = "{$this->table}/cover/new.jpg";
        Storage::disk('local')->put($newPath, 'new-content');

        // No surrounding transaction here — DB::afterCommit() must run
        // the deletion immediately, exactly as the old (buggy) code did
        // for this ordinary, non-rolled-back case. Confirms the fix
        // didn't just defer cleanup forever.
        $record->forceFill(['cover' => $newPath])->save();

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        $this->assertSame($newPath, $record->fresh()->cover);
    }

    /**
     * Phase 11C scope, verified here since it's the same safety theme:
     * the generated image endpoints require --guard (enforced at
     * generation time, Phase 6) — confirms the resulting routes
     * genuinely reject a request with no token at all, not just that
     * --images without --guard is rejected at generation time.
     */
    public function test_image_endpoints_reject_an_unauthenticated_request(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/v1/tx-safety-widget.php'));

        $upload = $this->postJson('/api/v1/tx-safety-widget/images/cover/upload', [
            'id' => 1,
            'file' => UploadedFile::fake()->image('cover.jpg', 10, 10),
        ]);
        $upload->assertStatus(401, $upload->getContent());

        $download = $this->postJson('/api/v1/tx-safety-widget/images/cover/download', ['id' => 1]);
        $download->assertStatus(401, $download->getContent());
    }

    /**
     * Proves {Name}MediaController's existing compensating-action logic
     * (Phase 6: "if the DB save fails after a new file was already
     * stored, delete that file again") actually works against a REAL
     * forced DB failure — not just read from the generated source as
     * presumed-correct.
     */
    public function test_orphaned_file_is_cleaned_up_when_the_db_save_fails_after_upload(): void
    {
        Storage::fake('local');

        $this->artisan('make:module', [
            'name' => $this->name,
            '--fields' => 'title:string:required',
            '--images' => 'cover:single',
            '--guard' => 'sanctum',
        ])->assertExitCode(0);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/v1/tx-safety-widget.php'));

        $modelClass = "App\\Models\\{$this->name}";
        $record = $modelClass::create(['title' => 'Gizmo', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;

        // Force the very next save() on this model to fail, simulating
        // a real DB-layer failure (constraint violation, connection
        // drop, etc.) AFTER the file has already been stored. Model
        // event listeners are static (keyed by class name) and would
        // otherwise outlive this test for the rest of the process —
        // flushed in a `finally` so it can never leak into a later test
        // that happens to reuse this exact class name.
        $modelClass::saving(function ($model) {
            throw new \RuntimeException('simulated DB failure after file store');
        });

        try {
            $upload = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/tx-safety-widget/images/cover/upload', [
                    'id' => $record->id,
                    'file' => UploadedFile::fake()->image('cover.jpg', 10, 10),
                ]);
        } finally {
            $modelClass::flushEventListeners();
        }

        // handleException() reports the uncaught RuntimeException as a
        // generic 500 (this codebase's established catch-all behavior,
        // Phase 3C) — the important assertion is what happens to the
        // FILE, not the exact status code.
        $upload->assertStatus(500, $upload->getContent());

        // The orphan check: list every file actually left on the fake
        // disk under this attribute's directory — there must be none,
        // since the one file that WAS stored should have been deleted
        // again when the save() it was meant to accompany failed.
        $filesLeftOnDisk = Storage::disk('local')->allFiles("{$this->table}/cover");
        $this->assertEmpty($filesLeftOnDisk, 'An uploaded file was orphaned on disk after its DB save failed: '.implode(', ', $filesLeftOnDisk));
        $this->assertNull($record->fresh()->cover);
    }
}
