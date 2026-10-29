<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateModelColumnsServiceCommand extends Command
{
    protected $signature = 'make:model-columns-service';
    protected $description = 'Generate the ModelColumnsService in app/Services/Basic';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Services/Basic');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/ModelColumnsService.php';
        if ($files->exists($filePath)) {
            $this->info("Overwriting existing Controller at: {$filePath}");
        }

        $stub = <<<'PHP'
<?php

namespace App\Services\Basic;

class ModelColumnsService {

    private static $instance;
    private static $model;
    private static $modelsFlyWight = [];
    private function __construct(){}

    public static function getServiceFor($model): self {
        self::$model = isset(self::$modelsFlyWight[$model])
            ? self::$modelsFlyWight[$model]
            : self::$modelsFlyWight[$model] = new $model;

        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getColumn(string $col): string {
        return self::$model->getFillable()[$col];
    }

    public function getColumns(): array {
        return self::$model->getFillable();
    }

    public function getHiddens(): array {
        return collect(self::$model->getHidden())
            ->mapWithKeys(fn($hidden) => [$hidden => true])
            ->toArray();
    }

    public function getExcel(): array {
        return self::$model->getExcel();
    }
}
PHP;

        $files->put($filePath, $stub);
        $this->info("Generated ModelColumnsService at: {$filePath}");
        return 0;
    }
}
