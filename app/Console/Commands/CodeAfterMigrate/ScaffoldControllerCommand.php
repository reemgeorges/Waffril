<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldControllerCommand extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:controller
        {model : The Eloquent model base name (e.g. Model)}';

    protected $description = 'Generate a FatherCrudController subclass for the given Model';

    public function handle(Filesystem $files)
    {

        $input = $this->argument('model');
        $modelClass = $this->handelModelScope($input);

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $base      = Str::studly($this->argument('model'));     // e.g. "Meal" or "PlanPackage"
        $key       = Str::snake($base);                         // e.g. "meal" or "plan_package"
        $namespace = "App\\Http\\Controllers\\{$base}";
        $directory = app_path("Http/Controllers/{$base}");
        $className = "{$base}Controller";

        // Paths
        $filePath = "{$directory}/{$className}.php";

        // 1) Ensure folder exists
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // 2) Stub template
        $stub = <<<'PHP'
<?php

namespace DummyNamespace;

use DummyFacadeFQCN;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FatherCrudController;
use DummyRequestFQCN;
use Illuminate\Http\Request;

class DummyClass extends FatherCrudController
{
    protected function setVariables() : void {
        $this->key = "DummyKey";
        $this->service = DummyFacadeShort::class;
        $this->createRequest = DummyRequestShort::class;
        $this->updateRequest = DummyRequestShort::class;
    }
}
PHP;

        // 3) Compute FQCNs & short names
        $facadeFQCN       = "App\\Facades\\Services\\{$base}\\{$base}Facade";
        $facadeShort      = "{$base}Facade";
        $requestFQCN      = "App\\Http\\Requests\\Model\\Store{$base}Request";
        $requestShort     = "Store{$base}Request";
        $replacements = [
            'DummyNamespace'     => $namespace,
            'DummyClass'         => $className,
            'DummyKey'           => $key,
            'DummyFacadeFQCN'    => "{$facadeFQCN}",
            'DummyFacadeShort'   => $facadeShort,
            'DummyRequestFQCN'   => "{$requestFQCN}",
            'DummyRequestShort'  => $requestShort,
        ];

        // 4) Replace placeholders
        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub
        );

        if ($files->exists($filePath)) {

            $this->info("Skipping because controller already exists.");
            return 0;

        }

        // 5) Write file
        $files->put($filePath, $content);
        $this->info("Generated Controller: {$filePath}");
        return 0;
    }
}
