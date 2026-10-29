<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldRouteCommand extends Command
{
    protected $signature = 'scaffold:routes
        {model   : The Eloquent model base name (e.g. Food)}
        {--path= : Custom routes file (default: routes/api.php)}
        {--guard= : Named guard already configured in config/auth.php (e.g. sanctum) to protect the generated routes. Required unless --public is passed.}
        {--public : Explicit opt-in to generate a fully public, unguarded route group. Off by default — required instead of --guard to produce a live, unauthenticated group.}
        {--with-delete : Also generate a delete route (soft-delete, sets deleted_at — reversible via --with-restore). Requires --guard; refused together with --public.}
        {--with-restore : Also generate a restore route (clears deleted_at). Requires --guard; refused together with --public.}
        {--with-force-delete : Also generate a permanent-delete (forceDelete) route. Requires --guard; refused together with --public. This permanently destroys data once hit — opt in deliberately.}';

    protected $description = 'Inject CRUD routes for a model, guarded by a real, already-configured auth guard by default-safe design';

    /**
     * Phase 1 (2026-10-25) security fix.
     *
     * Before this fix, this command ALWAYS appended a fully public,
     * unauthenticated route group (the exact pattern that caused the Phase 0
     * incident in routes/api.php: public create/update/activate/deactivate
     * for User/Test/Category). The admin/user "guarded injection" code path
     * existed but was dead: it referenced `$this->option('admin')` /
     * `$this->option('user')` options that were never declared in the
     * command signature, and injected into middleware aliases ('admin-auth',
     * 'user-auth') that are never registered in bootstrap/app.php (see
     * docs/starter-roadmap.md Phase 0.1 "Known follow-up", item on
     * GenerateAuthRouteGroupsCommand). So in practice the command had
     * exactly one live behavior: public injection.
     *
     * New default-safe behavior:
     *  - No flags at all -> the route block is generated but entirely
     *    commented out (a visible scaffold, zero live routes). This is the
     *    new default, replacing "always public".
     *  - --guard=NAME -> validated against config('auth.guards.NAME') at
     *    generation time (no DB access, no network call — pure config read).
     *    If that guard is not configured, the command FAILS (non-zero exit,
     *    clear error) and writes nothing — it never silently emits a
     *    middleware reference that would throw "Auth guard [NAME] is not
     *    defined" at request time. If the guard IS configured (e.g.
     *    'sanctum', which Laravel Sanctum's own service provider registers
     *    into config('auth.guards.sanctum') at boot time even though it is
     *    not written out in config/auth.php's guards array on disk), the
     *    routes are generated live, wrapped in
     *    Route::middleware("auth:{guard}").
     *  - --public -> explicit, deliberate opt-in to generate a live, fully
     *    public (unguarded) group — preserves the old behavior, but now only
     *    on request, matching CLAUDE.md's "Generated public endpoints
     *    require explicit opt-in; secure routes by default."
     *  - --with-restore / --with-force-delete -> opt-in additions for the
     *    restore()/forceDelete() actions BasicCrudService/FatherCrudController
     *    already support (Phase 1). Both REQUIRE --guard (never allowed with
     *    --public or with no guard at all) — forceDelete in particular is a
     *    permanent, irreversible operation and must never be reachable
     *    without deliberate guard configuration.
     */
    public function handle(Filesystem $files)
    {
        $input = $this->argument('model');
        $base = Str::studly($input);
        $uri = Str::kebab($base);
        $controller = "\\App\\Http\\Controllers\\{$base}\\{$base}Controller";
        $path = base_path($this->option('path') ?: 'routes/api.php');

        $guard = $this->option('guard');
        $public = (bool) $this->option('public');
        $withDelete = (bool) $this->option('with-delete');
        $withRestore = (bool) $this->option('with-restore');
        $withForceDelete = (bool) $this->option('with-force-delete');

        if (! $files->exists($path)) {
            $this->error("Routes file not found: {$path}");

            return 1;
        }

        if ($guard && $public) {
            $this->error('Pass either --guard or --public, not both.');

            return 1;
        }

        if ($public && ($withDelete || $withRestore || $withForceDelete)) {
            $this->error(
                'delete/restore/forceDelete routes cannot be generated on an explicitly public (--public) '.
                'group. Use --guard with a real configured guard instead.'
            );

            return 1;
        }

        if (($withDelete || $withRestore || $withForceDelete) && ! $guard) {
            $this->error(
                '--with-delete, --with-restore and --with-force-delete require --guard=NAME (a configured '.
                'guard). They are never generated without authentication.'
            );

            return 1;
        }

        if ($guard && is_null(config("auth.guards.{$guard}"))) {
            $this->error(
                "Guard [{$guard}] is not configured in config/auth.php (checked config('auth.guards.{$guard}')). ".
                'Configure the guard first, or pass --public to explicitly generate an unguarded group.'
            );

            return 1;
        }

        $lines = [
            "Route::get('/all/paginated', [{$controller}::class, 'allPaginated']);",
            "Route::get('/all',           [{$controller}::class, 'all']);",
            "Route::post('/show',         [{$controller}::class, 'show']);",
            "Route::post('/create',       [{$controller}::class, 'store']);",
            "Route::post('/update',       [{$controller}::class, 'update']);",
            "Route::post('/activate',     [{$controller}::class, 'activate']);",
            "Route::post('/deactivate',   [{$controller}::class, 'deactivate']);",
        ];

        if ($withDelete) {
            $lines[] = "Route::post('/delete',       [{$controller}::class, 'destroy']);";
        }

        if ($withRestore) {
            $lines[] = "Route::post('/restore',      [{$controller}::class, 'restore']);";
        }

        if ($withForceDelete) {
            $lines[] = "Route::post('/force-delete', [{$controller}::class, 'forceDelete']);";
        }

        $content = $files->get($path);

        // Idempotency guard: don't double-inject for this model, regardless
        // of which mode (disabled/guarded/public) a previous run used, and
        // regardless of whether a previous block was later hand-commented
        // out (e.g. the Phase 0 "DISABLED, see note above" treatment still
        // contains this same marker text as a substring).
        $legacyMarker = "// {$base} PUBLIC ROUTES";
        $newMarker = "// {$base} ROUTES (";

        if (Str::contains($content, $legacyMarker) || Str::contains($content, $newMarker)) {
            $this->info("Routes for '{$uri}' already exist in '{$path}'; skipping to avoid duplicate injection.");

            return 0;
        }

        if ($public) {
            $block = $this->buildBlock($base, $uri, $lines, 'public, unguarded — explicit --public opt-in', null);
            $files->append($path, $block);
            $this->warn("Appended PUBLIC, UNGUARDED routes for '{$uri}' (explicit --public opt-in).");

            return 0;
        }

        if ($guard) {
            $block = $this->buildBlock($base, $uri, $lines, "guarded by auth:{$guard}", $guard);
            $files->append($path, $block);
            $this->info("Appended routes for '{$uri}' guarded by auth:{$guard}.");

            return 0;
        }

        // Default: no --guard and no --public. Generate the scaffold fully
        // commented out — a visible, discoverable starting point with zero
        // live, reachable routes.
        $block = $this->buildDisabledBlock($base, $uri, $lines);
        $files->append($path, $block);
        $this->info(
            "No --guard/--public given: appended a DISABLED (commented-out) route scaffold for '{$uri}'. ".
            'Re-run with --guard=<configured-guard> or --public to make it live.'
        );

        return 0;
    }

    protected function buildBlock(string $base, string $uri, array $lines, string $modeLabel, ?string $guard): string
    {
        $block = PHP_EOL."// {$base} ROUTES ({$modeLabel})".PHP_EOL;

        if ($guard) {
            // Phase 4 (2026-10-25) security fix, same reasoning as
            // App\Http\Middleware\EnsureGuardIdentity's doc comment:
            // Sanctum's guard driver resolves a bearer token to whichever
            // model it actually belongs to, regardless of which named
            // guard is checking it — 'auth:{guard}' alone does not stop a
            // different guard's token from passing. Harmless no-op for a
            // model-agnostic guard like 'sanctum' (no specific provider to
            // mismatch against); genuinely protective for a model-specific
            // guard (admin/user/any custom module guard).
            $block .= "Route::middleware(['auth:{$guard}', 'guard.identity:{$guard}'])->prefix('{$uri}')->group(function () {".PHP_EOL;
        } else {
            $block .= "Route::prefix('{$uri}')->group(function () {".PHP_EOL;
        }

        foreach ($lines as $line) {
            $block .= "    {$line}".PHP_EOL;
        }

        $block .= '});'.PHP_EOL;

        return $block;
    }

    protected function buildDisabledBlock(string $base, string $uri, array $lines): string
    {
        $block = PHP_EOL."// {$base} ROUTES (disabled by default — no --guard/--public given at generation time)".PHP_EOL;
        $block .= "// Re-run: scaffold:routes {$base} --guard=<configured-guard> (once that guard exists".PHP_EOL;
        $block .= "// in config/auth.php) or: scaffold:routes {$base} --public — to make this group live.".PHP_EOL;
        $block .= "// Route::prefix('{$uri}')->group(function () {".PHP_EOL;

        foreach ($lines as $line) {
            $block .= "//     {$line}".PHP_EOL;
        }

        $block .= '// });'.PHP_EOL;

        return $block;
    }
}
