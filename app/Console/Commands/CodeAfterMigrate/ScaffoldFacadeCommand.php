<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldFacadeCommand extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:facade
        {model : Fully-qualified Eloquent model class (e.g. Model)}';

    protected $description = 'Generate a Facade for the given Model’s Service';

    public function handle(Filesystem $files)
    {
        $input = $this->argument('model');
        $modelClass = $this->handelModelScope($input);

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $short      = class_basename($modelClass);
        $facadeName = "{$short}Facade";
        $serviceKey = "{$short}Service";
        $namespace  = "App\\Facades\\Services\\{$short}";
        $dir        = app_path("Facades/Services/{$short}");
        $path       = "{$dir}/{$facadeName}.php";

        // Ensure the directory exists
        if (! $files->isDirectory($dir)) {
            $files->makeDirectory($dir, 0755, true);
            $this->info("Created directory: {$dir}");
        }

        // Stub for the facade
        $stub = <<<'PHP'
<?php

namespace DummyNamespace;

use Illuminate\Support\Facades\Facade;

class DummyFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'DummyServiceKey';
    }
}
PHP;

        // Replace placeholders
        $content = str_replace(
            ['DummyNamespace', 'DummyFacade', 'DummyServiceKey'],
            [$namespace, $facadeName, $serviceKey],
            $stub
        );

        if ($files->exists($path)) {

            $this->info("Skipping because facade already exists.");
            return 0;

        }

        // Write the file (overwrite if exists)
        $files->put($path, $content);
        $this->info("Generated Facade: {$path}");

        return 0;
    }
}
