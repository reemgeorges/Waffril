<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Filesystem\Filesystem;

/**
 * Phase 2B (2026-10-25) — retired.
 *
 * This command targeted `app_path('Http/Kernel.php')`, which has not
 * existed since this app moved to Laravel 11/12-style bootstrapping
 * (middleware aliases now live in bootstrap/app.php). Running it would
 * fatal on a missing file the moment it tried to register its middleware
 * alias — it was already completely broken, not just stylistically out of
 * date. Its config/auth.php guard/provider injection (regex-based
 * array-depth tracking) is also superseded by
 * App\Providers\AuthModulesServiceProvider, which fills in guard/provider
 * config from config/auth_modules.php at boot instead of hand-editing
 * config/auth.php.
 *
 * Rather than patch fragile logic that a better-designed replacement
 * already covers, this command now fails safely with no side effects and
 * points at that replacement: `php artisan make:auth-module {Model}`.
 */
class GenerateAuthTokenMiddlewareCommand extends BaseScaffoldCommand
{
    protected $signature = 'make:auth-token
        {model : The base name of your user model (e.g. Admin, Employee)}
        {--path= : Unused, kept for backward CLI compatibility}';

    protected $description = 'Retired — see make:auth-module. Writes nothing; always fails.';

    public function handle(Filesystem $files): int
    {
        $model = $this->argument('model');

        $this->error(
            'make:auth-token is retired: it targeted app/Http/Kernel.php, which does not exist in this '.
            'Laravel 12 app (middleware aliases live in bootstrap/app.php — see the guard.identity alias '.
            "registered there) and would have fatally errored. Use `php artisan make:auth-module {$model}` ".
            'instead — it generates a guard, provider, model, service, controller, and a disabled-by-default '.
            'routes file guarded by App\\Http\\Middleware\\EnsureGuardIdentity, all wired correctly for Laravel 12.'
        );

        return 1;
    }
}
