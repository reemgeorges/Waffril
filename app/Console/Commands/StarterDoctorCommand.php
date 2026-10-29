<?php

namespace App\Console\Commands;

use App\Contracts\Auth\OtpChannelProvider;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 10 (2026-10-26) — `php artisan starter:doctor`: a read-only
 * diagnostic sweep across this starter kit's own moving parts (PHP/
 * framework requirements, guard/provider config, middleware
 * registration, OTP channel config, generator command registration,
 * queue/storage config, route authorization visibility, model/schema
 * consistency, documentation consistency).
 *
 * Never writes, migrates, or seeds anything — every check here only
 * reads config/the filesystem/the route table/the DB schema (never row
 * data). Each check is reported as PASS, WARN, or FAIL:
 *  - FAIL: something that is actually broken right now (a referenced
 *    class/provider/guard that doesn't exist, a registered channel class
 *    that doesn't implement its contract).
 *  - WARN: something that is EXPECTED to be empty/unset on a fresh
 *    clone before the developer's own setup steps (migrations not run
 *    yet, storage:link not run yet, an unguarded route that may well be
 *    intentionally public like /login) — informational, not a defect.
 * Exit code is 1 only if any check FAILs; WARNs never fail the command,
 * since many of them are the normal, honest state of a project that
 * hasn't finished its own setup yet.
 */
class StarterDoctorCommand extends Command
{
    protected $signature = 'starter:doctor';

    protected $description = 'Read-only diagnostic sweep of this starter kit\'s framework, auth, generator, and documentation configuration';

    protected int $failures = 0;

    protected int $warnings = 0;

    public function handle(): int
    {
        $this->checkPhpAndExtensions();
        $this->checkFrameworkVersion();
        $this->checkGuardsAndProviders();
        $this->checkMiddlewareAliases();
        $this->checkPermissionTables();
        $this->checkModelSchemaConsistency();
        $this->checkRouteAuthorizationVisibility();
        $this->checkGeneratorCommandsRegistered();
        $this->checkOtpChannels();
        $this->checkQueueConfig();
        $this->checkStorageConfig();
        $this->checkDocumentationConsistency();

        $this->line('');
        if ($this->failures > 0) {
            $this->error("{$this->failures} failure(s), {$this->warnings} warning(s).");

            return 1;
        }

        $this->info("No failures, {$this->warnings} warning(s).");

        return 0;
    }

    protected function reportPass(string $message): void
    {
        $this->line("  [PASS] {$message}");
    }

    protected function reportWarn(string $message): void
    {
        $this->warnings++;
        $this->line("  [WARN] {$message}");
    }

    protected function reportFail(string $message): void
    {
        $this->failures++;
        $this->line("  [FAIL] {$message}");
    }

    protected function checkPhpAndExtensions(): void
    {
        $this->info('PHP & extensions');

        version_compare(PHP_VERSION, '8.2.0', '>=')
            ? $this->reportPass('PHP '.PHP_VERSION.' >= 8.2 (Laravel 12 requirement)')
            : $this->reportFail('PHP '.PHP_VERSION.' is below Laravel 12\'s 8.2 requirement');

        foreach (['pdo', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'bcmath', 'fileinfo', 'curl'] as $ext) {
            extension_loaded($ext)
                ? $this->reportPass("ext-{$ext} loaded")
                : $this->reportFail("ext-{$ext} is required by Laravel 12 but is not loaded");
        }
    }

    protected function checkFrameworkVersion(): void
    {
        $this->info('Framework');
        $this->reportPass('Laravel '.app()->version());
    }

    protected function checkGuardsAndProviders(): void
    {
        $this->info('Guards & providers');

        $providers = config('auth.providers', []);

        foreach (config('auth.guards', []) as $guardName => $guard) {
            $providerKey = $guard['provider'] ?? null;
            if ($providerKey === null) {
                // e.g. Sanctum's own 'sanctum' guard — model-agnostic by
                // design (see ScaffoldRouteCommand's doc comment), not a
                // misconfiguration.
                $this->reportPass("Guard \"{$guardName}\" has no provider (model-agnostic — expected for a driver like sanctum)");

                continue;
            }

            if (! isset($providers[$providerKey])) {
                $this->reportFail("Guard \"{$guardName}\" references provider \"{$providerKey}\", which does not exist in config('auth.providers')");

                continue;
            }

            $model = $providers[$providerKey]['model'] ?? null;
            if (! $model || ! class_exists($model)) {
                $this->reportFail("Provider \"{$providerKey}\" (guard \"{$guardName}\") references model \"{$model}\", which does not exist");

                continue;
            }

            $this->reportPass("Guard \"{$guardName}\" -> provider \"{$providerKey}\" -> {$model} (exists)");
        }
    }

    protected function checkMiddlewareAliases(): void
    {
        $this->info('Middleware aliases');

        // Mirrors bootstrap/app.php's own ->withMiddleware() alias list —
        // read back from the router's actual registered aliases, not
        // re-declared here, so this check can never silently drift from
        // what bootstrap/app.php really registers.
        $aliases = app(Router::class)->getMiddleware();

        if (empty($aliases)) {
            $this->reportWarn('No middleware aliases are registered at all — unexpected if bootstrap/app.php\'s ->withMiddleware() closure has any.');

            return;
        }

        foreach ($aliases as $alias => $class) {
            class_exists($class)
                ? $this->reportPass("Alias \"{$alias}\" -> {$class} (exists)")
                : $this->reportFail("Alias \"{$alias}\" -> {$class}, which does not exist");
        }
    }

    /**
     * Memoized: a genuinely unreachable database (wrong host, database
     * doesn't exist yet, credentials wrong) would otherwise throw an
     * UNCAUGHT QueryException the first time any check below called
     * Schema::hasTable() — crashing the whole command and silently
     * skipping every check still queued after it. Found by actually
     * running this command against this environment's real .env, not by
     * assuming Schema::hasTable() fails soft.
     */
    protected ?bool $canConnectCache = null;

    protected function canConnectToDatabase(): bool
    {
        // An INSTANCE property, deliberately not a function-local
        // `static` — a `static` local is scoped to the PHP process, not
        // to one command run, so it would stay `true` across every test
        // method in the same PHPUnit process once any one of them
        // successfully connected, masking a later test's genuinely
        // broken connection. A fresh instance of this command is created
        // per invocation, so an instance property resets correctly every
        // run — found by a real test failing only when run alongside
        // others in the same file, not in isolation.
        if ($this->canConnectCache === null) {
            try {
                // getPdo() alone isn't a strong enough probe: some
                // drivers don't actually open the connection until the
                // first real query, so getPdo() can return successfully
                // and let the FIRST Schema::hasTable() call below throw
                // uncaught instead. select 1 forces the exact same
                // connect-and-query path every later check will also take.
                DB::connection()->select('select 1');
                $this->canConnectCache = true;
            } catch (\Throwable $e) {
                $this->reportFail('Cannot connect to the configured database ('.config('database.default').'): '.$e->getMessage());
                $this->canConnectCache = false;
            }
        }

        return $this->canConnectCache;
    }

    protected function checkPermissionTables(): void
    {
        $this->info('Spatie permission tables');

        if (! class_exists(PermissionRegistrar::class)) {
            $this->reportWarn('spatie/laravel-permission is not installed — skipping (only relevant if a module uses --permissions).');

            return;
        }

        if (! $this->canConnectToDatabase()) {
            $this->reportWarn('Skipping — database unreachable (see failure above).');

            return;
        }

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            Schema::hasTable($table)
                ? $this->reportPass("Table \"{$table}\" exists")
                : $this->reportWarn("Table \"{$table}\" does not exist yet — run its migration before using --permissions on any module.");
        }
    }

    /**
     * Informational only (never FAILs on a missing table): a model whose
     * table doesn't exist yet almost always just means migrations
     * haven't been run on THIS connection yet, on a fresh clone — not a
     * defect in the model itself.
     */
    protected function checkModelSchemaConsistency(): void
    {
        $this->info('Model <-> schema consistency');

        if (! $this->canConnectToDatabase()) {
            $this->reportWarn('Skipping — database unreachable (see failure above).');

            return;
        }

        $modelFiles = File::glob(app_path('Models/*.php'));
        foreach ($modelFiles as $path) {
            $class = 'App\\Models\\'.basename($path, '.php');
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            try {
                $table = (new $class)->getTable();
            } catch (\Throwable $e) {
                $this->reportWarn("Could not instantiate {$class} to determine its table: ".$e->getMessage());

                continue;
            }

            Schema::hasTable($table)
                ? $this->reportPass("{$class} -> table \"{$table}\" exists")
                : $this->reportWarn("{$class} -> table \"{$table}\" does not exist yet — run its migration.");
        }
    }

    /**
     * Visibility only — an unguarded api/v1 route is frequently
     * INTENTIONAL (login, register, a public health check), so this
     * never FAILs, only reports counts and names for a human to review.
     */
    protected function checkRouteAuthorizationVisibility(): void
    {
        $this->info('Route authorization (informational)');

        $guarded = 0;
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $hasAuth = ! empty(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => str_starts_with($m, 'auth:') || str_starts_with($m, 'guard.identity')
            ));

            if ($hasAuth) {
                $guarded++;
            } else {
                $unguarded[] = $route->methods()[0].' '.$uri;
            }
        }

        $this->reportPass("{$guarded} api/v1/* route(s) carry an auth middleware.");
        if (! empty($unguarded)) {
            $this->reportWarn(count($unguarded).' api/v1/* route(s) carry no auth middleware — review that each is intentionally public:');
            foreach ($unguarded as $u) {
                $this->line("         - {$u}");
            }
        }
    }

    protected function checkGeneratorCommandsRegistered(): void
    {
        $this->info('Generator commands');

        $registered = array_keys(Artisan::all());

        foreach (['make:module', 'make:auth-module', 'openapi:build', 'docs:check-drift', 'starter:new', 'starter:doctor'] as $command) {
            in_array($command, $registered, true)
                ? $this->reportPass("`{$command}` is registered")
                : $this->reportFail("`{$command}` is NOT registered as an artisan command");
        }
    }

    protected function checkOtpChannels(): void
    {
        $this->info('OTP channel providers');

        foreach (config('otp.channels', []) as $name => $class) {
            if (! class_exists($class)) {
                $this->reportFail("OTP channel \"{$name}\" -> {$class}, which does not exist");

                continue;
            }

            (new \ReflectionClass($class))->implementsInterface(OtpChannelProvider::class)
                ? $this->reportPass("OTP channel \"{$name}\" -> {$class} (implements OtpChannelProvider)")
                : $this->reportFail("OTP channel \"{$name}\" -> {$class} does NOT implement OtpChannelProvider");
        }

        $default = config('otp.default_channel');
        if ($default && ! isset(config('otp.channels', [])[$default])) {
            $this->reportFail("otp.default_channel is \"{$default}\", which is not a key in otp.channels");
        }
    }

    protected function checkQueueConfig(): void
    {
        $this->info('Queue');

        $driver = config('queue.default');
        $driver === 'sync'
            ? $this->reportWarn('QUEUE_CONNECTION=sync — OTP delivery jobs run inline. Fine for local/test; a real deployment should use a real queue + a running `php artisan queue:work`.')
            : $this->reportPass("QUEUE_CONNECTION={$driver}");
    }

    protected function checkStorageConfig(): void
    {
        $this->info('Storage');

        foreach (['local', 'public'] as $disk) {
            config("filesystems.disks.{$disk}")
                ? $this->reportPass("Disk \"{$disk}\" is configured")
                : $this->reportFail("Disk \"{$disk}\" is not configured in config/filesystems.php");
        }

        File::exists(public_path('storage'))
            ? $this->reportPass('public/storage symlink exists (storage:link has been run)')
            : $this->reportWarn('public/storage symlink does not exist — run `php artisan storage:link` before serving anything from the public disk.');
    }

    protected function checkDocumentationConsistency(): void
    {
        $this->info('Documentation');

        if (! File::exists(base_path('docs/openapi/openapi.yaml'))) {
            $this->reportWarn('No docs/openapi/openapi.yaml yet — run `php artisan openapi:build` after generating at least one module with --with-openapi.');

            return;
        }

        $this->reportPass('docs/openapi/openapi.yaml exists — run `php artisan docs:check-drift` to verify it still matches the real routes.');
    }
}
