<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class GenerateAuthRouteGroupsCommand extends Command
{
    protected $signature = 'make:auth-groups
        {key : Middleware key, e.g. "user-auth" or "admin-auth"}
        {--path= : Path to routes file (default: routes/api.php)}';

    protected $description = 'Append a /{prefix} group guarded by the given middleware key into your API routes';

    public function handle(Filesystem $files)
    {
        // 1) Locate the routes file
        $path = base_path($this->option('path') ?: 'routes/api.php');
        if (! $files->exists($path)) {
            return $this->error("Routes file not found at {$path}");
        }

        $content = $files->get($path);

        // 2) Compute middleware key and URI prefix
        $key    = $this->argument('key');                         // e.g. "user-auth"
        $prefix = Str::before($key, '-auth');                     // => "user"
        $label  = Str::title(str_replace('-', ' ', $prefix));     // => "User"

        // 3) Build our route‐group stub
        $stub = <<<PHP

// ─── {$label} Routes ───────────────────────────────────────────────────────
Route::middleware('{$key}')
     ->prefix('{$prefix}')
     ->group(function () {
    // … your {$prefix}-only endpoints here …
});
PHP;

        // 4) Only append if we haven’t already defined that middleware+prefix
        if (Str::contains($content, "middleware('{$key}')")
            && Str::contains($content, "prefix('{$prefix}')"))
        {
            $this->info("Route group for '{$key}' already exists – skipping.");
        } else {
            $files->append($path, $stub . "\n");
            $this->info("Appended '{$label}' route group guarded by '{$key}'.");
        }

        return 0;
    }
}
