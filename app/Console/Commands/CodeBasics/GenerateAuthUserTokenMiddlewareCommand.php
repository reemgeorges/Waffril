<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Phase 2B (2026-10-25) — retired. Same reasoning as
 * App\Console\Commands\CodeAfterMigrate\GenerateAuthTokenMiddlewareCommand:
 * this targeted the no-longer-existing app/Http/Kernel.php and was already
 * fatally broken. Use `make:auth-module User` (or whichever module) instead.
 */
class GenerateAuthUserTokenMiddlewareCommand extends Command
{
    protected $signature = 'make:auth-user-token
        {--path= : Unused, kept for backward CLI compatibility}';

    protected $description = 'Retired — see make:auth-module. Writes nothing; always fails.';

    public function handle(Filesystem $files): int
    {
        $this->error(
            'make:auth-user-token is retired: it targeted app/Http/Kernel.php, which does not exist in this '.
            'Laravel 12 app, and would have fatally errored. Use `php artisan make:auth-module User` instead '.
            '(or whichever module name fits) — see App\\Providers\\AuthModulesServiceProvider and '.
            'config/auth_modules.php for how guards/providers are now wired up.'
        );

        return 1;
    }
}
