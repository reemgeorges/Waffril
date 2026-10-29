<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldFacadeBinding extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:facade-binding
        {model : The Eloquent model base name (e.g. Model)}';

    protected $description = 'Add a new [ModelService => App\\Services\\Model\\Model\\ModelService::class] entry into AppServiceProvider::$facades';

    public function handle(Filesystem $files)
    {
        $input = $this->argument('model');
        $modelClass = $this->handelModelScope($input);

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $base     = Str::studly($input);
        $alias    = "{$base}Service";
        $service  = "\\App\\Services\\Model\\{$base}\\{$base}Service";

        $providerPath = app_path('Providers/AppServiceProvider.php');
        if (! $files->exists($providerPath)) {
            return $this->error("AppServiceProvider not found at {$providerPath}");
        }

        $content = $files->get($providerPath);

        // Use a regex with three capture groups:
        //  1) everything up to and including the opening '['
        //  2) the current block content
        //  3) the closing '];'
        $pattern = '/(protected\s+\$facades\s*=\s*\[)(.*?)(\];)/ms';

        if (! preg_match($pattern, $content)) {
            return $this->error('Could not locate the protected $facades = [ … ]; block.');
        }

        $newLine = "    '{$alias}' => {$service}::class," . PHP_EOL;

        $newContent = preg_replace_callback($pattern, function($m) use ($newLine) {
            list(, $start, $inner, $end) = $m;

            // If the alias already exists, skip
            if (Str::contains($inner, $newLine)) {
                return $m[0];
            }

            // Inject the new line _after_ the opening bracket
            // and preserve any existing entries
            return $start
                . PHP_EOL
                . $newLine
                . $inner
                . $end;
        }, $content);

        $files->put($providerPath, $newContent);

        $this->info("Added facade binding '{$alias} => {$service}::class' to AppServiceProvider.");
        return 0;
    }

}
