<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldServiceCommand extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:service
        {model : Fully-qualified Eloquent model class (e.g. Model)}
        {--f|facade : Also scaffold the facade and bind it}';

    protected $description = 'Generate a BasicCrudService subclass for the given Model';

    public function handle(Filesystem $files)
    {
        $input = $this->argument('model');
        $modelClass = $this->handelModelScope($input);

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $baseName    = class_basename($modelClass);
        $serviceName = "{$baseName}Service";
        $namespace   = "App\\Services\\Model\\{$baseName}";
        $directory   = app_path("Services/Model/{$baseName}");
        $filePath    = "{$directory}/{$serviceName}.php";

        // 1) Ensure folder exists
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // 2) Stub template
        $stub = <<<'PHP'
<?php

namespace DummyNamespace;

use App\Services\Basic\BasicCrudService;
use App\Services\Basic\ModelColumnsService;
DummyModelNamespace;
DummyResourceNamespace;

class DummyService extends BasicCrudService
{
    /**
     * Override to set up modelColumnsService and resource.
     */
    protected function setVariables(): void
    {
        $this->modelColumnsService = ModelColumnsService::getServiceFor(
            $this->model = DummyModel::class
        );

        $this->resource = DummyResource::class;
    }
}
PHP;

// 3) Replace placeholders
        $content = str_replace(
            [
                'DummyNamespace',
                'DummyModelNamespace',
                'DummyResourceNamespace',
                'DummyService',
                'DummyModel',
                'DummyResource',
            ],
            [
                $namespace,                                 // e.g. App\Services\Model\Order
                "use {$modelClass}",                       // e.g. use App\Models\Order;
                "use App\Http\Resources\Model\\{$baseName}Resource", // e.g. use App\Http\Resources\Model\OrderResource;
                $serviceName,                               // e.g. OrderService
                $baseName,                                  // e.g. Order
                "{$baseName}Resource",                      // e.g. OrderResource
            ],
            $stub
        );

        if ($files->exists($filePath)) {

            $this->info("Skipping because service already exists.");
            return 0;

        }
        // 4) Write file
        $files->put($filePath, $content);
        $this->info("Generated Service: {$filePath}");

        if ($this->option('facade')) {

            $params = [
                'model' => $baseName,
            ];
            $this->call('scaffold:facade',$params);
            $this->call('scaffold:facade-binding',$params);

        }

        return 0;
    }
}
