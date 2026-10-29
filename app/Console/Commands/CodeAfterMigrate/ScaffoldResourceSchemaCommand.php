<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldResourceSchemaCommand extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:resource-schema
        {model : Fully-qualified Model class e.g. App\\Models\\User}';

    protected $description = 'Generate or update Resource for existing model';

    public function handle(Filesystem $files)
    {
        // 1) Determine the full model class
        $input = $this->argument('model');
        $modelClass = $this->handelModelScope($input);

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $baseName      = class_basename($modelClass);
        $resourceName  = "{$baseName}Resource";
        $directory     = app_path('Http/Resources/Model');
        $filePath      = "{$directory}/{$resourceName}.php";

        // 2) Ensure the target directory exists
        if (!$files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // 3) Stub template
        $stub = <<<'PHP'
<?php

namespace App\Http\Resources\Model;

use DummyModelFull;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Basic\BasicResource;
use App\Services\Basic\ModelColumnsService;

class DummyResourceClass extends BasicResource
{
    public function toArray(Request $request): array
    {
        return $this->initResource(
            ModelColumnsService::getServiceFor(
                DummyModelPure::class
            )
        );
    }

    protected function initResource($modelColumnsService): array
    {
        $this->result = parent::initResource($modelColumnsService);

        return array_merge($this->result, []);
    }
}
PHP;

        // 4) Replace placeholders
        $content = str_replace(
            ['DummyModelFull', 'DummyResourceClass',"DummyModelPure"],
            [$modelClass, $resourceName,$input],
            $stub
        );

        if ($files->exists($filePath)) {
            $existing = $files->get($filePath);

            // If initResource is already present, bail out
            if (Str::contains($existing, 'protected function initResource')) {
                $this->info("Skipping {$resourceName}.php because initResource() already exists.");
                return 0;
            }
        }

        // 5) Write (always overwrite)
        $files->put($filePath, $content);
        $this->info("Generated Resource: {$filePath}");

        return 0;
    }
}
