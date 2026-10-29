<?php

namespace App\Console\Commands\CodeBasics;

use App\Models\product;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class GenerateBaseAuthModelCommand extends Command
{
    protected $signature = 'make:base-auth-model
        {--soft-deletes : Generate withFilters() that uses onlyTrashed/withTrashed instead of a status column}
        {--uuid : Generate uuid setup with in the model}';

    protected $description = 'Generate the BaseAuthModel (with or without trashed‐based withFilters) in app/Models';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Models');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/BaseAuthModel.php';

        if ($files->exists($filePath)) {
            $this->info("Overwriting existing BaseAuthModel at: {$filePath}");
        }

        // Choose the appropriate stub for scopeWithFilters()
        $withFilters = $this->option('soft-deletes')? $this->withTrashedFilters() : $this->statusFilters();
        $mode = $this->option("uuid")? $this->uuidMode() : $this->idMode();
        $traits = $this->option('soft-deletes')? $this->trashedTraits() : $this->statusTraits();
        // Full class stub, interpolating the chosen withFilters() body
        $stub = <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaseAuthModel extends Authenticatable
{
    {$traits}


    protected \$search = [];
    protected \$excel = [];
    protected \$hidden = [
        'password',
    ];

{$mode}
{$withFilters}
}
PHP;

        // Write (always overwrite)
        $files->put($filePath, $stub);
        $this->info("Generated BaseAuthModel at: {$filePath}");

        return 0;
    }

    protected function withTrashedFilters() : mixed
    {
       return <<<'PHP'
    public function scopeWithFilters($query)
    {
        $request = request();

        // If 'search' is present, apply it across all $this->search columns
        $query->when($request->filled('search'),function ($query) use ($request){

            $useOr = false;
            foreach ($this->search as $col)
                if (!$useOr) {
                    $query->where($col, 'like', '%' . $request->search . '%');
                    $useOr = true;
                } else
                    $query->orWhere($col, 'like', '%' . $request->search . '%');

        });

        // Handle trashed status:
        $query->when($request->filled("status"),function ($query) use ($request) {

        if($request->status == 1)
            $query->withoutTrashed();
        else if($request->status == 0)
            $query->onlyTrashed();
        else
            $query->withTrashed();

        })->when(!$request->filled("status"),function ($query) use ($request) {

            $query->withoutTrashed();

        });

        return $query;
    }
PHP;
    }

    public function statusFilters() : mixed
    {
        return <<<'PHP'
    public function scopeWithFilters($query)
    {
        $request = request();

        // If 'search' is present, apply it across all $this->search columns
        $query->when($request->filled('search'),function ($query) use ($request){

            $useOr = false;
            foreach ($this->search as $col)
                if (!$useOr) {
                    $query->where($col, 'like', '%' . $request->search . '%');
                    $useOr = true;
                } else
                    $query->orWhere($col, 'like', '%' . $request->search . '%');

        });

        // Filter by a 'status' column
        $query->when($request->filled("status"),function ($query) use ($request){

        $query->where('status', $request->status);

        });

        return $query;
    }
PHP;
    }

    protected function uuidMode() : mixed
    {
        return <<<'PHP'
   protected $keyType = 'string';
    public $incrementing = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }
PHP;

    }

    protected function idMode() : mixed
    {
        return <<<'PHP'

                PHP;
    }

    protected function trashedTraits(): string
    {
        return <<<'PHP'
use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
PHP;
    }

    protected function statusTraits(): string
    {
        return <<<'PHP'
use HasApiTokens, HasFactory, Notifiable;
PHP;
    }


}
