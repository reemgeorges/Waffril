<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ScaffoldRequestSchemaCommand extends BaseScaffoldCommand
{
    protected $signature = 'scaffold:request-schema
        {model : Fully-qualified Model class e.g. App\\Models\\User}
        {--table=  : Optional schema table name}';
    protected $description = 'Generate or update FormRequest extending BasicRequest with validation rules';

    public function handle()
    {
        $modelClass    = $this->handelModelScope($this->argument('model'));

        if (! class_exists($modelClass)) {
            return $this->error("Model not found: {$modelClass}");
        }

        $requestClass  = 'Store' . class_basename($modelClass) . 'Request';
        $requestFull   = "App\\Http\\Requests\\Model\\{$requestClass}";
        $table         = $this->option('table') ?: (new $modelClass)->getTable();

        $this->ensureRequestExists($requestClass);
        $path = $this->getClassFilePath($requestFull);
        $this->updateRequestBaseClass($path);

        $columns     = $this->getColumns($table);
        $foreignKeys = $this->getForeignKeys($table);

        $rules = $this->parseRules($columns, $foreignKeys, $table);
        $rulesCode = $this->formatRulesMethod($rules);

        $content = $this->files->get($path);

        // 1) Remove the authorize() method entirely (if it was in default mode returns false;)
        $content = preg_replace(
            '/public function authorize\(\)\s*:\s*bool\s*\{[^}]*return\s+false;\s*[^}]*\}/s',
            '',
            $content
        );

        // 2) Now swap out the rules() method as before
        $content = preg_replace(
            '/public function rules\(\): array\s*\{[\s\S]*?\}/',
            $rulesCode,
            $content
        );

        $this->files->put($path, $content);

        $this->info("FormRequest scaffolding complete: {$path}");
    }

    protected function ensureRequestExists(string $class)
    {
        // 1) Target directory under Http/Requests/Model
        $dir  = app_path('Http/Requests/Model');
        $file = "{$dir}/{$class}.php";

        // 2) Make sure the folder exists
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
            $this->info("Created directory: {$dir}");
        }

        // 3) If the file doesn’t exist, generate in that sub-namespace
        if (!File::exists($file)) {
            // Passing "Model/{$class}" tells Artisan to put it in Http/Requests/Model
            Artisan::call('make:request', [
                'name' => "Model/{$class}"
            ]);

            $this->info("Created FormRequest stub: Model/{$class}");
        }
    }

    protected function getClassFilePath(string $class): string
    {
        return (new \ReflectionClass($class))->getFileName();
    }

    protected function updateRequestBaseClass(string $path)
    {
        $content = $this->files->get($path);

        // 1) Change the base class
        $content = preg_replace(
            '/extends\s+FormRequest/',
            'extends BasicRequest',
            $content
        );

        // 2) Ensure exactly one correct import in whichever requests namespace you’re in
        //    This matches either:
        //      namespace App\Http\Requests;
        //      namespace App\Http\Requests\Model;
        //    (or any one‑level deeper folder), and replaces it with:
        //      namespace …;
        //
        //      use App\Http\Requests\Basic\BasicRequest;
        $content = preg_replace(
            '/namespace\s+(App\\\\Http\\\\Requests(?:\\\\\w+)?);\s*(?:use\s+App\\\\Http\\\\Requests\\\\Basic\\\\BasicRequest;)?/m',
            "namespace $1;\n\nuse App\\Http\\Requests\\Basic\\BasicRequest;",
            $content
        );

        $this->files->put($path, $content);
    }

    /**
     * Phase 1 (2026-10-25) note on the "unique-on-update" fix:
     *
     * Generated requests still emit a plain `unique:{$table},{$name}` rule
     * string here (unchanged below) — but every generated request extends
     * App\Http\Requests\Basic\BasicRequest (see updateRequestBaseClass()
     * above), which now implements withValidator() to automatically rewrite
     * any `unique:` string rule into a Rule::unique(...)->ignore($id) when
     * the request carries an `id` field (i.e. it's an update, per the
     * BasicCrudService::update()/activate()/... convention of reading the
     * target row id from $request->id). That fix is centralized in the
     * shared base class precisely so this generator (and any other request
     * class, hand-written or generated) does not need to duplicate the
     * ignore-self logic per field/table. No per-rule-string change was
     * needed here as a result — see BasicRequest::withValidator() for the
     * actual fix.
     */
    protected function parseRules(array $columns, array $foreignKeys, string $table): array
    {
        $rules = [];
        foreach ($columns as $col) {
            $name = $col->COLUMN_NAME;
            if (in_array($name, ['id','created_at','updated_at','deleted_at'], true)) continue;

            $nullable = $col->IS_NULLABLE === 'YES';
            $type     = strtolower($col->DATA_TYPE);
            $fullType = $col->COLUMN_TYPE;

            $parts = [$nullable ? 'nullable' : 'required'];
            switch ($type) {
                case 'char': case 'varchar':
                case 'text': case 'mediumtext': case 'longtext':
                $parts[] = 'string';
                if (preg_match('/\((\d+)\)/', $fullType, $m)) {
                    $parts[] = 'max:' . $m[1];
                }
                break;
                case 'int': case 'smallint': case 'mediumint': case 'bigint':
                $parts[] = 'integer'; break;
                case 'tinyint':
                    $parts[] = Str::contains($fullType, '(1)') ? 'boolean' : 'integer'; break;
                case 'decimal': case 'float': case 'double':
                $parts[] = 'numeric'; break;
                case 'date':
                    $parts[] = 'date'; break;
                case 'datetime': case 'timestamp':
                $parts[] = 'date_format:Y-m-d H:i:s'; break;
                case 'json':
                    $parts[] = 'array'; break;
                case 'enum':
                    if (preg_match('/enum\((.*)\)/', $fullType, $m)) {
                        $vals = str_replace("'", '', $m[1]);
                        $parts[] = 'in:' . $vals;
                    }
                    break;
            }

            if ($col->COLUMN_KEY === 'UNI') {
                $parts[] = "unique:{$table},{$name}";
            }
            if (isset($foreignKeys[$name])) {
                $fk = $foreignKeys[$name];
                $parts[] = "exists:{$fk['table']},{$fk['column']}";
            }

            $rules[$name] = implode('|', $parts);
        }
        return $rules;
    }

    protected function formatRulesMethod(array $rules): string
    {
        $block = "public function rules(): array\n    {\n        return [\n";
        foreach ($rules as $field => $rule) {
            $block .= "            '$field' => '$rule',\n";
        }
        $block .= "        ];\n    }\n";
        return $block;
    }
}
