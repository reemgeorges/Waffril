<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 1 (2026-10-25): regression tests for the ScaffoldRouteCommand
 * security fix.
 *
 * Before this fix, `scaffold:routes` ALWAYS appended a fully public,
 * unauthenticated route group — the exact defect pattern Phase 0 had to
 * clean up after the fact in routes/api.php. The command now defaults to
 * generating a disabled (commented-out) scaffold, requires an explicitly
 * configured --guard (validated against config('auth.guards.*) at
 * generation time, never a guard that would throw "Auth guard [x] is not
 * defined" at request time) or an explicit --public opt-in to produce a
 * live route group, and refuses --with-restore/--with-force-delete unless a
 * real guard is present.
 *
 * These tests run the real Artisan command against a disposable, temporary
 * routes file created under routes/testing/ for this test only — never
 * against routes/api.php or any other live, registered route file. The temp
 * file is deleted in tearDown() regardless of test outcome.
 */
class ScaffoldRouteCommandSecurityTest extends TestCase
{
    private string $relativePath = 'routes/testing/_scaffold_cmd_security_test.php';

    private string $absolutePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->absolutePath = base_path($this->relativePath);

        File::ensureDirectoryExists(dirname($this->absolutePath));
        File::put($this->absolutePath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    }

    protected function tearDown(): void
    {
        if (File::exists($this->absolutePath)) {
            File::delete($this->absolutePath);
        }

        parent::tearDown();
    }

    public function test_default_with_no_flags_generates_a_disabled_commented_out_scaffold(): void
    {
        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
        ])->run();

        $this->assertSame(0, $exitCode);

        $content = File::get($this->absolutePath);

        $this->assertStringContainsString('// ScaffoldTemp ROUTES (disabled', $content);
        $this->assertStringContainsString("// Route::prefix('scaffold-temp')->group(function () {", $content);

        // No LIVE (uncommented) route group was added.
        $this->assertDoesNotMatchRegularExpression(
            '/^Route::(middleware|prefix)\(/m',
            $content
        );
    }

    public function test_guard_option_with_an_unconfigured_guard_fails_safely_and_writes_nothing(): void
    {
        $before = File::get($this->absolutePath);

        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'totally-not-a-real-guard',
        ])->run();

        $this->assertNotSame(0, $exitCode);

        // Nothing was appended on failure.
        $this->assertSame($before, File::get($this->absolutePath));
    }

    public function test_guard_option_with_a_real_configured_guard_generates_a_live_guarded_group(): void
    {
        // 'sanctum' is a real guard here: Laravel Sanctum's own service
        // provider registers it into config('auth.guards.sanctum') at boot
        // time (verified elsewhere in this suite — GET /api/user already
        // uses auth:sanctum successfully), even though config/auth.php's
        // guards array on disk only lists 'web'. It is therefore a safe,
        // genuinely-configured guard to exercise the success path with,
        // without inventing/registering a new guard ourselves.
        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
        ])->run();

        $this->assertSame(0, $exitCode);

        $content = File::get($this->absolutePath);

        // Phase 4: now pairs auth:{guard} with guard.identity:{guard} (see
        // ScaffoldRouteCommand::buildBlock()'s doc comment).
        $this->assertStringContainsString("Route::middleware(['auth:sanctum', 'guard.identity:sanctum'])->prefix('scaffold-temp')->group(function () {", $content);
        $this->assertStringContainsString(
            "Route::post('/create',       [\\App\\Http\\Controllers\\ScaffoldTemp\\ScaffoldTempController::class, 'store']);",
            $content
        );
    }

    public function test_public_option_generates_a_live_unguarded_group_only_on_explicit_opt_in(): void
    {
        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--public' => true,
        ])->run();

        $this->assertSame(0, $exitCode);

        $content = File::get($this->absolutePath);

        $this->assertStringContainsString("Route::prefix('scaffold-temp')->group(function () {", $content);
        $this->assertStringNotContainsString('auth:', $content);
    }

    public function test_with_restore_and_with_force_delete_require_a_real_guard_not_public(): void
    {
        $before = File::get($this->absolutePath);

        $publicExit = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--public' => true,
            '--with-restore' => true,
        ])->run();
        $this->assertNotSame(0, $publicExit);
        $this->assertSame($before, File::get($this->absolutePath));

        $noGuardExit = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--with-force-delete' => true,
        ])->run();
        $this->assertNotSame(0, $noGuardExit);
        $this->assertSame($before, File::get($this->absolutePath));
    }

    public function test_with_restore_and_with_force_delete_are_added_only_behind_a_real_guard(): void
    {
        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
            '--with-restore' => true,
            '--with-force-delete' => true,
        ])->run();

        $this->assertSame(0, $exitCode);

        $content = File::get($this->absolutePath);

        $this->assertStringContainsString(
            "Route::post('/restore',      [\\App\\Http\\Controllers\\ScaffoldTemp\\ScaffoldTempController::class, 'restore']);",
            $content
        );
        $this->assertStringContainsString(
            "Route::post('/force-delete', [\\App\\Http\\Controllers\\ScaffoldTemp\\ScaffoldTempController::class, 'forceDelete']);",
            $content
        );
    }

    public function test_with_delete_requires_a_real_guard_and_generates_a_soft_delete_route(): void
    {
        $rejected = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--public' => true,
            '--with-delete' => true,
        ])->run();
        $this->assertSame(1, $rejected);
        // setUp() already created the file with just the placeholder
        // header — confirm nothing was appended to it.
        $this->assertStringNotContainsString('ROUTES', File::get($this->absolutePath));

        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
            '--with-delete' => true,
        ])->run();
        $this->assertSame(0, $exitCode);

        $content = File::get($this->absolutePath);
        $this->assertStringContainsString(
            "Route::post('/delete',       [\\App\\Http\\Controllers\\ScaffoldTemp\\ScaffoldTempController::class, 'destroy']);",
            $content
        );
    }

    public function test_rerunning_for_the_same_model_does_not_duplicate_the_route_block(): void
    {
        $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
        ])->run();

        $afterFirstRun = File::get($this->absolutePath);

        $secondExit = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
        ])->run();

        $this->assertSame(0, $secondExit);
        $this->assertSame($afterFirstRun, File::get($this->absolutePath));
    }

    public function test_guard_and_public_together_are_rejected(): void
    {
        $before = File::get($this->absolutePath);

        $exitCode = $this->artisan('scaffold:routes', [
            'model' => 'ScaffoldTemp',
            '--path' => $this->relativePath,
            '--guard' => 'sanctum',
            '--public' => true,
        ])->run();

        $this->assertNotSame(0, $exitCode);
        $this->assertSame($before, File::get($this->absolutePath));
    }
}
