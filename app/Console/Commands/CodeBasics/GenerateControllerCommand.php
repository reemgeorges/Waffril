<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateControllerCommand extends Command
{
    protected $signature = 'make:default-controller';
    protected $description = 'Generate the Controller in app/Http/Controllers';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Http/Controllers');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/Controller.php';

        // If you want to warn that you’re overwriting:
        if ($files->exists($filePath)) {
            $this->info("Overwriting existing Controller at: {$filePath}");
        }

        $stub = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Http\Traits\GeneralTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests , GeneralTrait;
}
PHP;

        // Always write (this will overwrite if it already exists)
        $files->put($filePath, $stub);

        $this->info("Generated Controller at: {$filePath}");
        return 0;
    }

}
