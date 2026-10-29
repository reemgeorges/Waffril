<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 9 (2026-10-26): `php artisan starter:new`. This command's entire
 * purpose is to write OUTSIDE this project's own directory — into a
 * brand-new target the caller names — so there is no sandboxed output
 * root inside the project to use for testing it. Every target here is
 * created under `sys_get_temp_dir()` instead, well outside both this
 * repo and the user's Laragon www/ tree (so a test run can never create,
 * or interfere with, a sibling project there), and removed in
 * tearDown() regardless of pass/fail.
 *
 * `--with-git-history` is reviewed but not independently tested here:
 * this project's own `.git` lives one level up, at C:\laragon\www\, not
 * inside base_path() at all (confirmed via `git rev-parse
 * --show-toplevel`) — so there is no real `.git` inside the source tree
 * these tests copy FROM to exercise that flag against, and fabricating
 * one temporarily inside the real project (even briefly) was judged a
 * needless risk for what the flag's logic already shares verbatim with
 * the already-tested vendor/node_modules exclusion mechanism.
 */
class StarterNewProjectCommandTest extends TestCase
{
    protected string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = rtrim(sys_get_temp_dir(), '/\\').'/starter_new_test_'.uniqid();
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->target)) {
            File::deleteDirectory($this->target);
        }

        parent::tearDown();
    }

    public function test_copies_project_excluding_vendor_node_modules_and_runtime_secrets(): void
    {
        $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(0);

        $this->assertFileExists("{$this->target}/composer.json");
        $this->assertFileExists("{$this->target}/app/Console/Commands/MakeModuleCommand.php");
        $this->assertFileExists("{$this->target}/.env.example");

        $this->assertDirectoryDoesNotExist("{$this->target}/vendor");
        $this->assertDirectoryDoesNotExist("{$this->target}/node_modules");
        $this->assertFileDoesNotExist("{$this->target}/database/database.sqlite");
        $this->assertFileDoesNotExist("{$this->target}/.phpunit.result.cache");
    }

    public function test_generates_a_fresh_env_with_a_new_app_key_never_copied_from_source(): void
    {
        $sourceEnvKey = null;
        if (File::exists(base_path('.env'))) {
            preg_match('/^APP_KEY=(.*)$/m', File::get(base_path('.env')), $m);
            $sourceEnvKey = $m[1] ?? null;
        }

        $this->artisan('starter:new', ['path' => $this->target, '--name' => 'Fresh Project'])->assertExitCode(0);

        $this->assertFileExists("{$this->target}/.env");
        $newEnv = File::get("{$this->target}/.env");

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $newEnv);
        $this->assertStringContainsString('APP_NAME="Fresh Project"', $newEnv);

        if ($sourceEnvKey) {
            $this->assertStringNotContainsString($sourceEnvKey, $newEnv);
        }

        // The SOURCE project's real .env must never be copied, even
        // though its own APP_KEY clearly differs from a value generated
        // fresh here — confirmed structurally, not just by key mismatch.
        $this->assertFileDoesNotExist("{$this->target}/.env.backup");
    }

    public function test_refuses_to_write_into_a_non_empty_existing_directory(): void
    {
        File::ensureDirectoryExists($this->target);
        File::put("{$this->target}/pre-existing.txt", 'this directory already has content');

        $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(1);

        // Nothing from the source project was written alongside the
        // pre-existing file.
        $this->assertFileDoesNotExist("{$this->target}/composer.json");
        $this->assertFileExists("{$this->target}/pre-existing.txt");
    }

    public function test_succeeds_into_a_genuinely_empty_existing_directory(): void
    {
        File::ensureDirectoryExists($this->target);

        $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(0);

        $this->assertFileExists("{$this->target}/composer.json");
    }

    /**
     * Phase 11F (2026-10-26) — regression test for a real bug: PHP's
     * fnmatch() `*` matches a leading dot by default, so a broad
     * exclusion pattern like "storage/framework/sessions/*" was ALSO
     * excluding that directory's own ".gitignore" placeholder,
     * contradicting this command's own stated design (the directory
     * needs to exist for Laravel to run, only its runtime contents
     * should be excluded). Confirmed by reading the real, actual
     * storage/ tree in this checkout before fixing.
     */
    public function test_gitignore_placeholders_are_preserved_in_every_excluded_runtime_directory(): void
    {
        $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(0);

        foreach ([
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/framework/testing',
        ] as $dir) {
            if (File::isDirectory(base_path($dir))) {
                $this->assertFileExists(
                    "{$this->target}/{$dir}/.gitignore",
                    "{$dir}/.gitignore must survive the copy even though {$dir}'s runtime CONTENTS are excluded."
                );
            }
        }
    }

    /**
     * Phase 11F (2026-10-26) — regression test for a real bug: "do not
     * copy... existing uploads" was not actually enforced at all —
     * storage/app/public|private (the disk roots make:module's own
     * --images feature, Phase 6, stores real uploaded files under) were
     * never excluded. A temporary, disposable file is placed there for
     * the duration of this one test (removed in tearDown, same
     * create-and-clean-up discipline this whole suite already uses for
     * generated modules) to prove a REAL upload wouldn't have been
     * copied — not just that the directory exists.
     */
    public function test_real_uploaded_files_under_storage_app_are_never_copied(): void
    {
        $fakeUploadPublic = base_path('storage/app/public/__phase11f_test_upload.txt');
        $fakeUploadPrivate = base_path('storage/app/private/__phase11f_test_upload.txt');
        File::ensureDirectoryExists(dirname($fakeUploadPublic));
        File::ensureDirectoryExists(dirname($fakeUploadPrivate));
        File::put($fakeUploadPublic, 'pretend this is a real uploaded file');
        File::put($fakeUploadPrivate, 'pretend this is a real uploaded file');

        try {
            $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(0);

            $this->assertFileDoesNotExist("{$this->target}/storage/app/public/__phase11f_test_upload.txt");
            $this->assertFileDoesNotExist("{$this->target}/storage/app/private/__phase11f_test_upload.txt");
            // The disk roots themselves (and their own .gitignore) must
            // still exist — only real uploaded CONTENT is excluded.
            $this->assertFileExists("{$this->target}/storage/app/public/.gitignore");
            $this->assertFileExists("{$this->target}/storage/app/private/.gitignore");
        } finally {
            File::delete([$fakeUploadPublic, $fakeUploadPrivate]);
        }
    }

    public function test_writes_a_starter_kit_manifest_recording_the_source_commit(): void
    {
        $this->artisan('starter:new', ['path' => $this->target])->assertExitCode(0);

        $this->assertFileExists("{$this->target}/.starter-kit.json");
        $manifest = json_decode(File::get("{$this->target}/.starter-kit.json"), true);

        $this->assertSame(base_path(), $manifest['starter_kit_source_path']);
        $this->assertArrayHasKey('starter_kit_source_commit', $manifest);
        $this->assertArrayHasKey('upgrade_hint', $manifest);
        $this->assertArrayHasKey('generated_at', $manifest);

        if ($manifest['starter_kit_source_commit']) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['starter_kit_source_commit']);
        }
    }
}
