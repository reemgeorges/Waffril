<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
class GenerateGeneralTraitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:general-trait';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an GeneralTrait under App\Http\Traits';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files)
    {
        $name      = "GeneralTrait";
        $directory = app_path('Http/Traits');
        $filePath  = "{$directory}/{$name}.php";

        // 1) Ensure folder exists
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        // 2) If file already exists, warn and skip
        if ($files->exists($filePath)) {
            return $this->error("Trait already exists: {$filePath}");
        }

        // 3) Stub with placeholders
        $stub = <<<'PHP'
<?php

namespace App\Http\Traits;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

trait GeneralTrait
{
    public function apiResponse($data = null, bool $status = true, $error = null, $statusCode = 200)
    {
        $array = [
            'data' => $data,
            'status' => $status ,
            'error' => $error,
            'statusCode' => $statusCode
        ];
        return response($array, $statusCode);

    }

    public function unAuthorizeResponse()
    {
        return $this->apiResponse(null, 0, 'Unauthorize', 401);
    }

    public function notFoundResponse($more)
    {
        return $this->apiResponse(null, 0, $more, 404);
    }

    public function requiredField($message)
    {
        // return $this->apiResponse(null, false, $message, 200);
        return $this->apiResponse(null, false, $message, 400);
    }

    public function forbiddenResponse($message = "Forbidden")
    {
        return $this->apiResponse(null, false, $message, 403);
    }

    public function internalServer($message)
    {
        return $this->apiResponse(null, false,$message, 500);
    }

    public function handleException(\Exception $e)
    {
        if ($e instanceof ModelNotFoundException) {

            $modelName = class_basename($e->getModel());
            return $this->notFoundResponse("$modelName not found");

        } elseif ($e instanceof ValidationException) {

            $errors = $e->validator->errors();
            return $this->requiredField($errors->first());

        } elseif ($e instanceof HttpResponseException) {

            return $e->getResponse();

        } elseif ($e instanceof \Illuminate\Database\QueryException) {

            return $this->handleQueryException($e);

        } else {

            return $this->apiResponse(null, false, $e->getMessage(), 500);

        }
    }

    protected function handleQueryException(\Illuminate\Database\QueryException $e)
    {
        $errorCode = isset($e->errorInfo[1])? $e->errorInfo[1] : null; // Error code from the database

        switch ($errorCode) {
            case 1062: // Duplicate entry Like Unique Email Twice
                return $this->requiredField( "Duplicate entry found.");
            case 1451: // Cannot delete or update due to foreign key constraint
                return $this->requiredField("Cannot delete or update as it is referenced elsewhere.");
            case 1452: // Cannot add or update a child row due to foreign key constraint
                return $this->requiredField("Foreign key constraint violation.");
            case 1054:
                return $this->requiredField("Column not found!!");
            default:
                return $this->internalServer("Database error: " . $e->getMessage());
        }
    }
}
PHP;

        // 5) Write the file
        $files->put($filePath, $stub);
        $this->info("Generated trait: {$filePath}");

        return 0;
    }
}
