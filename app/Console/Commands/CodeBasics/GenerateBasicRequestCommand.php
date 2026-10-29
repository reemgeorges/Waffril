<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateBasicRequestCommand extends Command
{
    protected $signature = 'make:basic-request';
    protected $description = 'Generate the BasicRequest in app/Http/Requests/Basic';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Http/Requests/Basic');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/BasicRequest.php';
        if ($files->exists($filePath)) {
            $this->info("Overwriting existing Controller at: {$filePath}");
        }

        $stub = <<<'PHP'
<?php

namespace App\Http\Requests\Basic;

use App\Http\Traits\GeneralTrait;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class BasicRequest extends FormRequest
{
    use GeneralTrait;

    protected $modelColumnsService;

    public function __construct($model = null) {

        $this->modelColumnsService = ($model)? ModelColumnsService::getServiceFor(
            $model
        ) : null;

    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException($this->requiredField($validator->errors()->first()));
    }
}

PHP;

        $files->put($filePath, $stub);
        $this->info("Generated BasicRequest at: {$filePath}");
        return 0;
    }
}
