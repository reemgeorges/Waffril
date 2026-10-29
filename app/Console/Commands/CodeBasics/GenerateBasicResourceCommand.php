<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateBasicResourceCommand extends Command
{
    protected $signature = 'make:basic-resource';
    protected $description = 'Generate the BasicRequest in app/Http/Resources/Basic';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Http/Resources/Basic');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/BasicResource.php';
        if ($files->exists($filePath)) {
            $this->info("Overwriting existing Controller at: {$filePath}");
        }

        $stub = <<<'PHP'
<?php

namespace App\Http\Resources\Basic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BasicResource extends JsonResource
{
    protected $result = [];
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    protected function initResource($modelColumnsService) : array {

        $cols = $modelColumnsService->getColumns();
        $hiddens = $modelColumnsService->getHiddens();

        foreach($cols as $col)
         if(!isset($hiddens[$col]))
           $this->result[$col] = $this->{$col};

        return array_merge($this->result,[

            "id" => $this->id,
            "is_deleted" => !!$this->deleted_at,

           ]);

    }

}
PHP;

        $files->put($filePath, $stub);
        $this->info("Generated BasicResource at: {$filePath}");
        return 0;
    }
}
