<?php

namespace App\Console\Commands\Initialize;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldAllModelsCommand extends Command
{
    protected $signature = 'scaffold:all-models
        {model? : (Optional) Base model name to scaffold (e.g. User)}
        {--r|resource   : Also scaffold API Resource}
        {--req|request  : Also scaffold FormRequest}
        {--s|service    : Also scaffold Service + Facade + Binding}
        {--c|controller : Also scaffold Controller}
        {--ro|routes        : Inject full CRUD into routes}';

    protected $description = 'Scaffold models, requests, resources, controllers, services, and routes for app/Models';

    public function handle(Filesystem $files)
    {
        // Locate models folder
        $modelDir = app_path('Models');
        if (! $files->isDirectory($modelDir)) {
            return $this->error("Models directory not found: {$modelDir}");
        }

        // Determine which files to process
        $arg = $this->argument('model');
        $models = collect($files->files($modelDir))
            ->map->getFilename()
            ->filter(function ($file) use ($arg) {
                if ($arg) {
                    return Str::before($file, '.php') === Str::studly($arg);
                }
                return ! in_array($file, ['BaseModel.php', 'BaseAuthModel.php'], true);
            })
            ->map(fn($file) => pathinfo($file, PATHINFO_FILENAME))
            ->values()
            ->all();

        if (empty($models)) {
            return $this->info('No models to scaffold.');
        }

        $this->info('Scaffolding models: ' . implode(', ', $models));

        foreach ($models as $base) {
            $this->info("\n--- {$base} ---");
            $params = ['model' => $base];

            // 1) Model schema
            $this->call('scaffold:model-schema', $params);

            // 2) Form Request
            if ($this->option('request')) {
                $this->call('scaffold:request-schema', $params);
            }

            // 3) API Resource
            if ($this->option('resource')) {
                $this->call('scaffold:resource-schema', $params);
            }

            // 4) Service + Facade + Binding
            if ($this->option('service')) {
                $this->call('scaffold:service', $params);
                $this->call('scaffold:facade', $params);
                $this->call('scaffold:facade-binding', $params);
            }

            // 5) Controller
            if ($this->option('controller')) {
                $this->call('scaffold:controller', $params);
            }

            // 6) Routes
            if ($this->option('routes')) {
                $this->call('scaffold:routes', $params);
            }

            $this->info("✔ Completed: {$base}");
        }

        return 0;
    }
}
