<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BuildsOpenApiFragments;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Phase 4 (2026-10-25) — Unified Dynamic Module Generator.
 *
 * `php artisan make:module Product --fields="..." [options]` or
 * `php artisan make:module Product --interactive`.
 *
 * Generates a non-auth CRUD module on the same "Father/Basic" pattern
 * Phase 1 standardized (BaseModel + BasicCrudService + FatherCrudController +
 * BasicRequest + BasicResource) — the same pattern Category/Test/User
 * already use. Deliberately field-spec-driven, never database-introspecting:
 * every file this command writes (migration, model, request, factory) is
 * built directly from the parsed field list, so --dry-run is meaningfully
 * dry (zero DB access at generation time) and nothing here ever needs a
 * migration to have been run first.
 *
 * Consolidation, not duplication: this command REUSES the existing,
 * already-tested scaffold:* commands wherever they are already DB-less at
 * generation time — scaffold:resource-schema, scaffold:service,
 * scaffold:controller, scaffold:routes are all called internally rather
 * than reimplemented. Only migration/model/request/factory are generated
 * directly here, because scaffold:model-schema/scaffold:request-schema
 * (the two DB-introspecting legacy commands) require the table to already
 * exist in a real, migrated database — incompatible with a dry-run-safe,
 * migration-never-required-at-generation-time design. Those two legacy
 * commands, and the scaffold:all-models orchestrator built on top of them,
 * are left completely untouched and still work exactly as before for
 * anyone with an existing migrated table to introspect — "consolidate or
 * safely retire ... only after verifying compatibility" (CLAUDE.md): they
 * are NOT retired, since verifying full compatibility for retirement is
 * out of this phase's scope, and they still serve their original,
 * different use case.
 *
 * Idempotency / partial-failure safety: every individual file write is
 * independently skip-if-exists (never overwrites without --force) — the
 * same design make:auth-module (Phase 2B/3B) already proved out. A run
 * interrupted partway through is safe to simply re-run: already-written
 * files are left alone, missing ones are filled in. No separate
 * transactional "rollback" is implemented on top of that — deleting files
 * a user might already be editing on a retry would itself be destructive,
 * and the idempotent-skip design already gives the same practical safety
 * net without that risk.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : Module name, e.g. Product or Category}
        {--fields= : Semicolon-separated field specs: name:type[:params][:modifiers]. Types: string,text,integer,bigInteger,decimal,float,boolean,date,datetime,json. Modifiers: required,nullable,unique,index. Example: "title:string:required;price:decimal:8,2:required;description:text:nullable"}
        {--manifest= : Path to a JSON file (an object with a "fields" array of name/type/params/nullable/unique/index objects) instead of --fields}
        {--interactive : Prompt for fields and options interactively instead of --fields/--manifest}
        {--relations= : Semicolon-separated relationship specs: name:type:RelatedModel[:param]. Types: belongsTo,hasOne,hasMany,belongsToMany,morphTo,morphMany. belongsTo/morphTo 4th segment may be "required" (default nullable); morphMany 4th segment is the morph method name (default: snake(current model)). Example: "category:belongsTo:Category;images:hasMany:ProductImage"}
        {--images= : Semicolon-separated image attribute specs: name:single or name:multiple[:maxCount]. Adds a secure upload/delete/reorder/download sub-API for each, behind the SAME guard as the rest of the module (requires --guard — see class doc comment). Example: "cover:single;gallery:multiple:10"}
        {--images-public : Store generated image attributes on the public disk (web-accessible via storage:link) instead of the private "local" disk default.}
        {--images-max-kb=10240 : Maximum upload size per file, in kilobytes, for every --images attribute of this module.}
        {--stats : Generate a GET <route>/stats endpoint (totals, active/inactive/soft-deleted counts, sum/avg/min/max for numeric fields, optional ?from=/?to= date range, filter-aware via the model\'s own withFilters()). Requires --guard.}
        {--stats-group-by= : Comma-separated allowlist of --fields names a client may request via ?group_by= on the stats endpoint. A name not in --fields is a generation error; a client-requested name not in this allowlist is a 500 at request time, never silently honored.}
        {--guard= : Protect generated mutation routes behind this already-configured guard (e.g. admin). Required unless --public.}
        {--public : Explicit opt-in for live, unguarded mutation routes. Mutually exclusive with --guard.}
        {--crud=create,read,update,delete,activate,deactivate : Comma list from create,read,update,delete,activate,deactivate,restore,forceDelete}
        {--permissions : Generate a Policy enforced on every instance action (view/update/activate/deactivate/delete/restore/forceDelete via controller-level Gate::authorize(), viewAny/create via can: middleware) — requires --guard. See class doc comment for why instance abilities can\'t use can: middleware directly.}
        {--owner-column= : Optional column name, e.g. user_id. When set, the generated policy ALSO allows an actor whose own id matches that column on the target record, in addition to the usual manage-role check. Only meaningful with --permissions.}
        {--with-seeder : Also generate database/seeders/<Name>Seeder.php (never run automatically)}
        {--with-tests : Also generate tests/Feature/<Name>ModuleTest.php}
        {--with-openapi : Also generate docs/openapi/modules/<module>.yaml — a complete, accurate per-module fragment (every endpoint this module actually has, with its real guard/security and request schema), aggregated across modules by openapi:build (Phase 8).}
        {--dry-run : Print the generation plan without writing anything}
        {--force : Overwrite files that already exist (use with care)}';

    protected $description = 'Generate a complete, secure-by-default CRUD module (migration, model, request, resource, service, controller, routes, optional policy/seeder/tests/OpenAPI)';

    use BuildsOpenApiFragments;

    protected const KNOWN_TYPES = ['string', 'text', 'integer', 'bigInteger', 'decimal', 'float', 'boolean', 'date', 'datetime', 'json'];

    protected const KNOWN_CRUD = ['create', 'read', 'update', 'delete', 'activate', 'deactivate', 'restore', 'forceDelete'];

    protected const KNOWN_RELATION_TYPES = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany', 'morphTo', 'morphMany'];

    protected const KNOWN_IMAGE_TYPES = ['single', 'multiple'];

    protected const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    protected Filesystem $files;

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        $name = Str::studly($this->argument('name'));
        $table = Str::snake(Str::pluralStudly($name));
        $key = Str::snake($name);
        $routeFile = Str::kebab($name);
        $uri = Str::kebab(Str::pluralStudly($name));

        $guard = $this->option('guard');
        $public = (bool) $this->option('public');
        $permissions = (bool) $this->option('permissions');

        if ($guard && $public) {
            $this->error('Pass either --guard or --public, not both.');

            return 1;
        }

        if ($permissions && ! $guard) {
            $this->error('--permissions requires --guard (a policy with no actor guard to check against is meaningless).');

            return 1;
        }

        $crud = array_values(array_intersect(
            array_map('trim', explode(',', (string) $this->option('crud'))),
            self::KNOWN_CRUD
        ));
        if (empty($crud)) {
            $crud = ['create', 'read', 'update', 'delete', 'activate', 'deactivate'];
        }

        try {
            $fields = $this->resolveFields();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if (empty($fields)) {
            $this->error('At least one field is required (via --fields, --manifest, or --interactive).');

            return 1;
        }

        try {
            $relations = $this->resolveRelationSpecs($name);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        try {
            $images = $this->resolveImageSpecs();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if (! empty($images) && ! $guard) {
            // Unauthenticated upload/delete/reorder is a materially bigger
            // risk surface than plain CRUD — not satisfied by --public,
            // same reasoning as --permissions requiring a real --guard.
            $this->error('--images requires --guard (media upload/delete/reorder must not be reachable without authentication).');

            return 1;
        }

        $imagesDisk = $this->option('images-public') ? 'public' : 'local';
        $imagesMaxKb = (int) $this->option('images-max-kb');

        $stats = (bool) $this->option('stats');

        if ($stats && ! $guard) {
            $this->error('--stats requires --guard (aggregate counts/sums must not be reachable without authentication).');

            return 1;
        }

        $knownFieldNames = array_map(fn ($f) => $f['name'], $fields);
        $statsGroupBy = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('stats-group-by')))));
        foreach ($statsGroupBy as $groupField) {
            if (! in_array($groupField, $knownFieldNames, true)) {
                $this->error("--stats-group-by names \"{$groupField}\", which is not one of this module's --fields: ".implode(', ', $knownFieldNames).'.');

                return 1;
            }
        }

        $numericFields = array_values(array_filter(
            $fields,
            fn ($f) => in_array($f['type'], ['integer', 'bigInteger', 'decimal', 'float'], true)
        ));

        $ownerColumn = $this->option('owner-column') ?: null;
        if ($ownerColumn && ! $permissions) {
            $this->error('--owner-column requires --permissions (it only ever affects the generated policy).');

            return 1;
        }

        if ($this->files->exists(app_path("Models/{$name}.php")) && ! $this->option('force')) {
            $this->info("Module '{$name}' already exists (app/Models/{$name}.php) — skipping (nothing written). Use --force to regenerate individual files.");

            return 0;
        }

        $context = compact('name', 'table', 'key', 'routeFile', 'uri', 'fields', 'guard', 'public', 'permissions', 'crud', 'relations', 'images', 'imagesDisk', 'imagesMaxKb', 'stats', 'statsGroupBy', 'numericFields', 'ownerColumn');

        $plan = $this->buildPlan($context);

        if ($this->option('dry-run')) {
            $this->info("Dry run — would generate the following for module '{$name}':");
            foreach ($plan as $path => $description) {
                $this->line("  - {$path}  ({$description})");
            }
            $this->line('  - (reused) scaffold:resource-schema, scaffold:service, scaffold:controller, scaffold:routes');

            return 0;
        }

        foreach ($plan as $path => $description) {
            $this->writeIfAbsent($path, fn () => $this->contentFor($path, $context));
        }

        // Reuse existing, already-tested, DB-less-at-generation-time
        // commands for the pieces they already handle correctly.
        $this->call('scaffold:resource-schema', ['model' => $name]);
        $this->call('scaffold:service', ['model' => $name]);
        $this->call('scaffold:controller', ['model' => $name]);

        if (! empty($relations)) {
            $this->wireResourceRelations($context);
        }

        $this->generateRoutes($context);

        if ($permissions) {
            $this->wirePermissionMiddleware($context);
            $this->wireControllerPermissionOverrides($context);
        }

        if (! empty($images)) {
            $this->generateImageRoutes($context);
        }

        if ($stats) {
            $this->generateStatsRoute($context);
        }

        $this->info("Module '{$name}' generated. Nothing was migrated, seeded, or made live automatically.");
        $this->line('Next steps:');
        $this->line('  1. Review the generated migration, then run it yourself against a dev/test database.');
        $this->line("  2. Review routes/api/v1/{$routeFile}.php — it is disabled by default unless --guard or --public was passed.");
        if ($permissions) {
            $this->line("  3. Grant the \"manage-{$key}\" role to an admin yourself when ready — nothing does this automatically.");
        }
        if (! empty($images) && $imagesDisk === 'local') {
            $this->line('  '.($permissions ? '4' : '3').'. Image attributes use the private "local" disk — files are not web-accessible by a direct URL; serve them only through the generated download endpoint.');
        }

        return 0;
    }

    /**
     * --fields (semicolon-separated) | --manifest (JSON file) | --interactive.
     * All three converge on the same shape: array<{name,type,params,nullable,unique,index}>.
     */
    protected function resolveFields(): array
    {
        if ($this->option('interactive')) {
            return $this->promptForFields();
        }

        if ($manifestPath = $this->option('manifest')) {
            if (! $this->files->exists($manifestPath)) {
                throw new \RuntimeException("Manifest file not found: {$manifestPath}");
            }

            $decoded = json_decode($this->files->get($manifestPath), true);
            if (! is_array($decoded) || ! isset($decoded['fields']) || ! is_array($decoded['fields'])) {
                throw new \RuntimeException('Manifest must be a JSON object with a "fields" array.');
            }

            return array_map(fn ($f) => $this->normalizeFieldArray($f), $decoded['fields']);
        }

        if ($fieldsOption = $this->option('fields')) {
            return $this->parseFieldsString($fieldsOption);
        }

        return [];
    }

    protected function promptForFields(): array
    {
        $fields = [];
        $this->line('Enter fields one at a time. Leave the name blank to finish.');

        while (true) {
            $name = $this->ask('Field name');
            if (! $name) {
                break;
            }

            $type = $this->choice('Field type', self::KNOWN_TYPES, 0);
            $params = [];
            if ($type === 'decimal') {
                $params = [
                    (string) $this->ask('Precision', 8),
                    (string) $this->ask('Scale', 2),
                ];
            }

            $fields[] = [
                'name' => Str::snake($name),
                'type' => $type,
                'params' => $params,
                'nullable' => $this->confirm('Nullable?', false),
                'unique' => $this->confirm('Unique?', false),
                'index' => $this->confirm('Indexed?', false),
            ];
        }

        return $fields;
    }

    protected function parseFieldsString(string $spec): array
    {
        $fields = [];

        foreach (array_filter(array_map('trim', explode(';', $spec))) as $part) {
            $segments = explode(':', $part);
            $name = Str::snake(array_shift($segments) ?? '');
            $type = array_shift($segments) ?: 'string';

            if (! in_array($type, self::KNOWN_TYPES, true)) {
                throw new \RuntimeException("Unknown field type \"{$type}\" for field \"{$name}\". Known types: ".implode(', ', self::KNOWN_TYPES));
            }

            $modifiers = [];
            $params = [];
            foreach ($segments as $seg) {
                if (in_array($seg, ['required', 'nullable', 'unique', 'index'], true)) {
                    $modifiers[] = $seg;
                } else {
                    $params = array_map('trim', explode(',', $seg));
                }
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'params' => $params,
                'nullable' => in_array('nullable', $modifiers, true),
                'unique' => in_array('unique', $modifiers, true),
                'index' => in_array('index', $modifiers, true),
            ];
        }

        return $fields;
    }

    // ===================== Relationship helpers =====================

    /**
     * --relations (semicolon-separated): name:type:RelatedModel[:param].
     * Converges to array<{name,type,related,required,morphName}>.
     */
    protected function resolveRelationSpecs(string $selfName): array
    {
        $specsOption = $this->option('relations');
        if (! $specsOption) {
            return [];
        }

        $specs = [];
        foreach (array_filter(array_map('trim', explode(';', $specsOption))) as $part) {
            $segments = array_map('trim', explode(':', $part));
            $name = Str::snake(array_shift($segments) ?? '');
            $type = array_shift($segments) ?: '';

            if (! in_array($type, self::KNOWN_RELATION_TYPES, true)) {
                throw new \RuntimeException("Unknown relation type \"{$type}\" for relation \"{$name}\". Known types: ".implode(', ', self::KNOWN_RELATION_TYPES));
            }

            $related = array_shift($segments) ?: null;
            if (! $related) {
                throw new \RuntimeException("Relation \"{$name}\" needs a related model class, e.g. \"{$name}:{$type}:RelatedModel\".");
            }
            $related = Str::studly($related);

            if ($related === $selfName) {
                // Self-referencing belongsToMany would need distinct pivot
                // column names (e.g. user_id/friend_id) this generic,
                // name-from-table-name pivot generator can't infer safely —
                // rejected for every relation type, not just that one.
                throw new \RuntimeException("Relation \"{$name}\" references its own module ({$selfName}) — self-referencing relations are only supported via a hand-edited model after generation.");
            }

            $param = array_shift($segments) ?: null;

            $specs[] = [
                'name' => $name,
                'type' => $type,
                'related' => $related,
                'required' => $param === 'required',
                'morphName' => ($type === 'morphMany' && $param && $param !== 'required') ? $param : null,
            ];
        }

        return $specs;
    }

    protected function relationReturnType(string $type): string
    {
        return match ($type) {
            'belongsTo' => 'BelongsTo',
            'hasOne' => 'HasOne',
            'hasMany' => 'HasMany',
            'belongsToMany' => 'BelongsToMany',
            'morphTo' => 'MorphTo',
            'morphMany' => 'MorphMany',
            default => 'Relation',
        };
    }

    protected function relationMethodBody(array $relation, array $c): string
    {
        $related = "\\App\\Models\\{$relation['related']}";

        return match ($relation['type']) {
            'belongsTo' => "return \$this->belongsTo({$related}::class, '{$relation['name']}_id');",
            'hasOne' => "return \$this->hasOne({$related}::class);",
            'hasMany' => "return \$this->hasMany({$related}::class);",
            'belongsToMany' => "return \$this->belongsToMany({$related}::class);",
            'morphTo' => 'return $this->morphTo();',
            'morphMany' => "return \$this->morphMany({$related}::class, '".($relation['morphName'] ?: $c['key'])."');",
            default => throw new \RuntimeException("No relation method body for type {$relation['type']}"),
        };
    }

    /**
     * Pivot table name, Laravel's own convention: both related table names,
     * singularized, sorted alphabetically, joined by an underscore (e.g.
     * "categories" + "products" -> "category_product").
     */
    protected function pivotTableName(string $tableA, string $tableB): string
    {
        $names = [Str::singular($tableA), Str::singular($tableB)];
        sort($names);

        return implode('_', $names);
    }

    // ===================== Image attribute helpers =====================

    /**
     * --images (semicolon-separated): name:single or name:multiple[:maxCount].
     * Converges to array<{name,type,maxCount}>.
     */
    protected function resolveImageSpecs(): array
    {
        $specsOption = $this->option('images');
        if (! $specsOption) {
            return [];
        }

        $specs = [];
        foreach (array_filter(array_map('trim', explode(';', $specsOption))) as $part) {
            $segments = array_map('trim', explode(':', $part));
            $name = Str::snake(array_shift($segments) ?? '');
            $type = array_shift($segments) ?: '';

            if (! in_array($type, self::KNOWN_IMAGE_TYPES, true)) {
                throw new \RuntimeException("Unknown image type \"{$type}\" for image attribute \"{$name}\". Known types: ".implode(', ', self::KNOWN_IMAGE_TYPES));
            }

            $maxCount = $type === 'multiple' ? (int) (array_shift($segments) ?: 10) : 1;

            $specs[] = ['name' => $name, 'type' => $type, 'maxCount' => $maxCount];
        }

        return $specs;
    }

    protected function normalizeFieldArray(array $f): array
    {
        $type = $f['type'] ?? 'string';
        if (! in_array($type, self::KNOWN_TYPES, true)) {
            throw new \RuntimeException("Unknown field type \"{$type}\" in manifest for field \"".($f['name'] ?? '?').'".');
        }

        return [
            'name' => Str::snake($f['name'] ?? ''),
            'type' => $type,
            'params' => array_map('strval', $f['params'] ?? []),
            'nullable' => (bool) ($f['nullable'] ?? false),
            'unique' => (bool) ($f['unique'] ?? false),
            'index' => (bool) ($f['index'] ?? false),
        ];
    }

    protected function buildPlan(array $c): array
    {
        $plan = [
            database_path('migrations/'.now()->format('Y_m_d_His')."_create_{$c['table']}_table.php") => 'migration',
            app_path("Models/{$c['name']}.php") => 'model',
            app_path("Http/Requests/Model/Store{$c['name']}Request.php") => 'form request (shared create+update)',
            database_path("factories/{$c['name']}Factory.php") => 'factory',
            // scaffold:controller (reused below) generates a controller
            // that references App\Facades\Services\{Name}\{Name}Facade by
            // that exact FQCN — it must exist before the controller is
            // ever autoloaded. Written here with an FQCN-direct accessor
            // (same pattern as every Phase 2B/3B-generated auth facade)
            // instead of calling scaffold:facade+scaffold:facade-binding,
            // which would need a risky text-injection into
            // AppServiceProvider — not needed since Laravel's container
            // auto-resolves a parameterless-constructor concrete service
            // class without any binding.
            app_path("Facades/Services/{$c['name']}/{$c['name']}Facade.php") => 'facade',
        ];

        if ($this->option('permissions')) {
            $plan[app_path("Policies/{$c['name']}Policy.php")] = 'policy';
        }

        if ($this->option('with-seeder')) {
            $plan[database_path("seeders/{$c['name']}Seeder.php")] = 'seeder (not auto-run)';
        }

        if ($this->option('with-tests')) {
            $plan[base_path("tests/Feature/{$c['name']}ModuleTest.php")] = 'tests';
        }

        if ($this->option('with-openapi')) {
            $plan[base_path("docs/openapi/modules/{$c['routeFile']}.yaml")] = 'OpenAPI fragment (complete, accurate, per-module)';
        }

        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] !== 'belongsToMany') {
                continue;
            }
            $pivotTable = $this->pivotTableName($c['table'], Str::snake(Str::pluralStudly($relation['related'])));

            // A pivot migration's filename always carries a fresh
            // timestamp — Schema::hasTable() inside the stub only makes a
            // SECOND migration's up() a safe no-op at migrate time, it
            // does not stop a redundant FILE from being queued. Both
            // sides of a many-to-many relation reach buildPlan()
            // independently (once when generating this module, again if
            // the related module is generated afterward with the inverse
            // relation), so check by table name, on disk, before queueing —
            // not just by exact path like writeIfAbsent() does for every
            // other file.
            if (! empty($this->files->glob(database_path("migrations/*_create_{$pivotTable}_table.php")))) {
                continue;
            }

            $plan[database_path('migrations/'.now()->format('Y_m_d_His')."_create_{$pivotTable}_table.php")] = "pivot table ({$pivotTable})";
        }

        if (! empty($c['images'])) {
            $plan[app_path("Http/Controllers/{$c['name']}/{$c['name']}MediaController.php")] = 'media controller (upload/delete/reorder/download)';
        }

        if ($c['stats'] ?? false) {
            $plan[app_path("Http/Controllers/{$c['name']}/{$c['name']}StatsController.php")] = 'stats controller (totals/sum/avg/min/max/group-by)';
        }

        return $plan;
    }

    protected function writeIfAbsent(string $path, callable $content): void
    {
        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->info('Skipping (already exists): '.$path);

            return;
        }

        $dir = dirname($path);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($path, $content());
        $this->info('Generated: '.$path);
    }

    protected function contentFor(string $path, array $c): string
    {
        $path = str_replace('\\', '/', $path);

        return match (true) {
            Str::contains($path, '_table.php') && Str::contains($path, 'create_')
                && ! Str::contains($path, "create_{$c['table']}_table.php") => $this->pivotMigrationStub($path, $c),
            Str::contains($path, '/migrations/') => $this->migrationStub($c),
            Str::contains($path, '/Models/') => $this->modelStub($c),
            Str::contains($path, '/Requests/') => $this->requestStub($c),
            Str::contains($path, '/factories/') => $this->factoryStub($c),
            Str::contains($path, '/Facades/') => $this->facadeStub($c),
            Str::contains($path, '/Policies/') => $this->policyStub($c),
            Str::contains($path, 'MediaController.php') => $this->mediaControllerStub($c),
            Str::contains($path, 'StatsController.php') => $this->statsControllerStub($c),
            Str::contains($path, '/seeders/') => $this->seederStub($c),
            Str::contains($path, 'ModuleTest.php') => $this->testStub($c),
            Str::contains($path, '/openapi/') => $this->openApiStub($c),
            default => throw new \RuntimeException("No stub for {$path}"),
        };
    }

    // ===================== Field helpers =====================

    protected function migrationColumnLine(array $field): string
    {
        $name = $field['name'];
        $params = $field['params'];

        $call = match ($field['type']) {
            'string' => "\$table->string('{$name}')",
            'text' => "\$table->text('{$name}')",
            'integer' => "\$table->integer('{$name}')",
            'bigInteger' => "\$table->bigInteger('{$name}')",
            'decimal' => "\$table->decimal('{$name}', ".($params[0] ?? '8').', '.($params[1] ?? '2').')',
            'float' => "\$table->float('{$name}')",
            'boolean' => "\$table->boolean('{$name}')->default(false)",
            'date' => "\$table->date('{$name}')",
            'datetime' => "\$table->dateTime('{$name}')",
            'json' => "\$table->json('{$name}')",
            default => "\$table->string('{$name}')",
        };

        if ($field['nullable']) {
            $call .= '->nullable()';
        }
        if ($field['unique']) {
            $call .= '->unique()';
        }

        $line = "            {$call};";

        if ($field['index'] && ! $field['unique']) {
            $line .= "\n            \$table->index('{$name}');";
        }

        return $line;
    }

    protected function castTypeFor(array $field): ?string
    {
        return match ($field['type']) {
            'integer', 'bigInteger' => 'integer',
            'decimal' => 'decimal:'.($field['params'][1] ?? '2'),
            'float' => 'float',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'datetime',
            'json' => 'array',
            default => null,
        };
    }

    protected function validationRuleFor(array $field, string $table): string
    {
        $parts = [$field['nullable'] ? 'nullable' : 'required'];

        $parts[] = match ($field['type']) {
            'string' => 'string|max:255',
            'text' => 'string',
            'integer', 'bigInteger' => 'integer',
            'decimal', 'float' => 'numeric',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'date_format:Y-m-d H:i:s',
            'json' => 'array',
            default => 'string',
        };

        if ($field['unique']) {
            // BasicRequest::withValidator() already rewrites this into
            // Rule::unique(...)->ignore($id) on update (Phase 1) — no
            // per-field change needed here for that.
            $parts[] = "unique:{$table},{$field['name']}";
        }

        return implode('|', $parts);
    }

    protected function fakerExpressionFor(array $field): string
    {
        // A unique column needs a unique faker value, or factory()->count(2)
        // (or just two sequential test runs within the same test method)
        // will eventually collide and fail the DB constraint — flaky, not
        // a real bug in the generated code, but in the generated *data*.
        $unique = $field['unique'] ? '->unique()' : '';

        return match ($field['type']) {
            'string' => "fake(){$unique}->words(3, true)",
            'text' => "fake(){$unique}->paragraph()",
            'integer', 'bigInteger' => "fake(){$unique}->numberBetween(1, 1000000)",
            'decimal', 'float' => 'fake()->randomFloat('.($field['params'][1] ?? 2).', 1, 1000)',
            'boolean' => 'fake()->boolean()',
            'date' => "fake(){$unique}->date()",
            'datetime' => "fake(){$unique}->dateTime()->format('Y-m-d H:i:s')",
            'json' => '[]',
            default => "fake(){$unique}->word()",
        };
    }

    // ===================== Stubs =====================

    /**
     * belongsTo/morphTo columns live on THIS table and must be migrated
     * alongside the regular fields. hasOne/hasMany/belongsToMany/morphMany
     * never add a column here — their foreign key (or pivot table) lives
     * on the RELATED side instead (see pivotMigrationStub() for
     * belongsToMany). Deliberately NOT a DB-level ->constrained() foreign
     * key: two independently-generated modules referencing each other have
     * no guaranteed migration order, and a hard FK constraint pointing at a
     * table that doesn't exist yet at migrate time would fail the whole
     * migration run. An indexed plain column keeps this safe regardless of
     * generation/migration order; a developer can add the DB-level
     * constraint by hand once both tables are confirmed to exist.
     */
    protected function relationMigrationLines(array $c): string
    {
        $lines = [];

        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $nullable = $relation['required'] ? '' : '->nullable()';
                $lines[] = "            \$table->unsignedBigInteger('{$relation['name']}_id'){$nullable};";
                $lines[] = "            \$table->index('{$relation['name']}_id');";
            } elseif ($relation['type'] === 'morphTo') {
                $nullable = $relation['required'] ? '' : '->nullable()';
                $lines[] = "            \$table->unsignedBigInteger('{$relation['name']}_id'){$nullable};";
                $lines[] = "            \$table->string('{$relation['name']}_type'){$nullable};";
                $lines[] = "            \$table->index(['{$relation['name']}_id', '{$relation['name']}_type']);";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Column names a belongsTo/morphTo relation adds to THIS table, which
     * therefore also need to be mass-assignable — otherwise
     * Model::create($request->validated()) (what BasicCrudService::create()/
     * update() actually call) silently drops them even though they passed
     * validation, leaving the foreign key null no matter what the client
     * sent. Found via a real end-to-end test, not by inspecting generated
     * file existence.
     */
    protected function relationFillableColumns(array $c): array
    {
        $columns = [];

        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $columns[] = "{$relation['name']}_id";
            } elseif ($relation['type'] === 'morphTo') {
                $columns[] = "{$relation['name']}_id";
                $columns[] = "{$relation['name']}_type";
            }
        }

        return $columns;
    }

    /**
     * `single` -> one nullable string column (a storage path, never a raw
     * client-supplied path — see {Name}MediaController's doc comment).
     * `multiple` -> one nullable json column holding an ORDERED array of
     * paths; index 0 is the primary image by convention (no separate
     * "is_primary" column — reorder-to-front IS setting primary, see
     * mediaControllerStub()).
     */
    protected function imageMigrationLines(array $c): string
    {
        $lines = [];

        foreach ($c['images'] ?? [] as $image) {
            $lines[] = $image['type'] === 'single'
                ? "            \$table->string('{$image['name']}')->nullable();"
                : "            \$table->json('{$image['name']}')->nullable();";
        }

        return implode("\n", $lines);
    }

    protected function migrationStub(array $c): string
    {
        $lines = implode("\n", array_map(fn ($f) => $this->migrationColumnLine($f), $c['fields']));
        $relationLines = $this->relationMigrationLines($c);
        if ($relationLines !== '') {
            $lines .= "\n".$relationLines;
        }
        $imageLines = $this->imageMigrationLines($c);
        if ($imageLines !== '') {
            $lines .= "\n".$imageLines;
        }

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * `is_active` and `deleted_at` are always included — every module
 * generated on this codebase's BaseModel/BasicCrudService pattern needs
 * both unconditionally (BasicCrudService::activate()/deactivate() always
 * write `is_active`; BaseModel always uses the SoftDeletes trait, which
 * needs `deleted_at` to exist). This is not configurable per-module here
 * on purpose — the shared service/model base classes are what make it
 * mandatory, not this generator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('{$c['table']}')) {
            return;
        }

        Schema::create('{$c['table']}', function (Blueprint \$table) {
            \$table->id();
{$lines}
            \$table->boolean('is_active')->default(true);
            \$table->timestamps();
            \$table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$c['table']}');
    }
};

PHP;
    }

    /**
     * $path's own filename already carries the pivot table name baked in
     * by buildPlan() ({timestamp}_create_{pivot}_table.php) — parsed back
     * out here rather than threading it through $c, since $c describes
     * the MODULE being generated, not the (possibly unrelated-looking)
     * pivot table name.
     */
    protected function pivotMigrationStub(string $path, array $c): string
    {
        if (! preg_match('/_create_(?<table>.+)_table\.php$/', basename($path), $m)) {
            throw new \RuntimeException("Could not parse pivot table name from {$path}");
        }
        $table = $m['table'];

        $relatedTable = null;
        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] !== 'belongsToMany') {
                continue;
            }
            $candidateTable = Str::snake(Str::pluralStudly($relation['related']));
            if ($this->pivotTableName($c['table'], $candidateTable) === $table) {
                $relatedTable = $candidateTable;
                break;
            }
        }
        if (! $relatedTable) {
            throw new \RuntimeException("Could not find a belongsToMany relation in {$c['name']} matching pivot table \"{$table}\".");
        }

        $selfColumn = Str::singular($c['table']).'_id';
        $relatedColumn = Str::singular($relatedTable).'_id';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot table for a belongsToMany relation, generated by
 * `make:module {$c['name']}` (Phase 5, 2026-10-25). No DB-level foreign key
 * constraints, for the same cross-module migration-ordering reason
 * documented on relationMigrationLines() — both sides reference tables
 * that may not exist yet depending on generation order; a developer can
 * add the constraints by hand once both tables are confirmed to exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('{$table}')) {
            return;
        }

        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            \$table->unsignedBigInteger('{$selfColumn}');
            \$table->unsignedBigInteger('{$relatedColumn}');
            \$table->index(['{$selfColumn}', '{$relatedColumn}']);
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;
    }

    protected function modelStub(array $c): string
    {
        $fillable = implode("\n", array_map(fn ($f) => "        '{$f['name']}',", $c['fields']));
        $relationFillable = implode("\n", array_map(fn ($col) => "        '{$col}',", $this->relationFillableColumns($c)));
        if ($relationFillable !== '') {
            $fillable .= "\n".$relationFillable;
        }

        $casts = array_filter(array_map(function ($f) {
            $cast = $this->castTypeFor($f);

            return $cast ? "        '{$f['name']}' => '{$cast}'," : null;
        }, $c['fields']));
        // 'is_active' is always fillable (added below, outside the field
        // loop) but must ALSO always be cast to boolean — every other
        // BaseModel-based model in this codebase (Admin/User/Category/Test)
        // does this; without it, $record->is_active reads back as the raw
        // DB value (an int 0/1 on most drivers), which fails strict
        // assertTrue()/assertFalse() checks even though the value is
        // logically correct.
        $casts[] = "        'is_active' => 'boolean',";
        foreach ($c['images'] ?? [] as $image) {
            if ($image['type'] === 'multiple') {
                $casts[] = "        '{$image['name']}' => 'array',";
            }
        }
        $castsBlock = "\n    protected \$casts = [\n".implode("\n", $casts)."\n    ];\n";

        $images = $c['images'] ?? [];
        $fileAttributesBlock = '';
        if (! empty($images)) {
            // Deliberately NOT added to $fillable above: these columns are
            // only ever set via {Name}MediaController's own
            // forceFill()->save() after a file has been validated and
            // safely stored — never through the generic create()/update()
            // mass-assignment path (which has no validation rule for them
            // either, since they aren't --fields). $fileAttributes/
            // $fileDisk reuse BaseModel's existing, already-proven
            // replace/delete/soft-delete-safe file cleanup hooks (Phase 1)
            // unchanged — this phase does not touch that mechanism, only
            // configures it per generated module.
            $entries = implode("\n", array_map(
                fn ($i) => "        '{$i['name']}' => '".($i['type'] === 'single' ? 'single' : 'array')."',",
                $images
            ));
            $fileAttributesBlock = "\n    protected array \$fileAttributes = [\n{$entries}\n    ];\n    protected string \$fileDisk = '{$c['imagesDisk']}';\n";
        }

        $relations = $c['relations'] ?? [];
        $relationImports = implode("\n", array_values(array_unique(array_map(
            fn ($r) => 'use Illuminate\\Database\\Eloquent\\Relations\\'.$this->relationReturnType($r['type']).';',
            $relations
        ))));
        if ($relationImports !== '') {
            $relationImports = "\n".$relationImports;
        }

        $relationMethods = implode("\n\n", array_map(function ($r) use ($c) {
            $returnType = $this->relationReturnType($r['type']);

            return "    public function {$r['name']}(): {$returnType}\n    {\n        {$this->relationMethodBody($r, $c)}\n    }";
        }, $relations));
        if ($relationMethods !== '') {
            $relationMethods = "\n\n".$relationMethods;
        }

        $allowedIncludes = implode(', ', array_map(fn ($r) => "'{$r['name']}'", $relations));
        $allowedIncludesBlock = empty($relations)
            ? ''
            : "\n    protected array \$allowedIncludes = [{$allowedIncludes}];\n";

        return <<<PHP
<?php

namespace App\Models;

use App\Models\BaseModel;
use Database\Factories\\{$c['name']}Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;{$relationImports}

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * @use HasFactory<{$c['name']}Factory>
 */
class {$c['name']} extends BaseModel
{
    use HasFactory;

    protected \$fillable = [
{$fillable}
        'is_active',
    ];
{$castsBlock}{$fileAttributesBlock}{$allowedIncludesBlock}{$relationMethods}
}

PHP;
    }

    /**
     * One upload/delete/[reorder/]download method PER image attribute,
     * rather than one generic method keyed by an attribute name string —
     * keeps each attribute's single-vs-multiple logic simple and
     * correct to generate, and makes an unknown/mistyped attribute a
     * routing 404 rather than a runtime branch this controller has to
     * defend against itself.
     */
    protected function mediaControllerStub(array $c): string
    {
        $mimeList = implode("', '", self::ALLOWED_IMAGE_MIME_TYPES);
        $methods = implode("\n\n", array_map(
            fn ($image) => $image['type'] === 'single'
                ? $this->singleImageMethods($image, $c)
                : $this->multipleImageMethods($image, $c),
            $c['images']
        ));

        return <<<PHP
<?php

namespace App\Http\Controllers\\{$c['name']};

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneralTrait;
use App\Models\\{$c['name']};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generated by `make:module {$c['name']}` (Phase 6, 2026-10-25; extended
 * Phase 11C, 2026-10-26, for --permissions authorization).
 *
 * Every method here resolves the owning record via
 * {$c['name']}::findOrFail(\$request->id) and only ever reads/writes ITS
 * OWN image attribute(s):
 *  - a new upload is always stored under a generator-chosen UUID
 *    filename, on the configured disk — never the client's original
 *    filename or any client-supplied path segment (prevents path
 *    traversal and filename collisions/overwrites);
 *  - delete/reorder/download never act on a path the client merely
 *    NAMES unless that exact string is already present on THIS record's
 *    own attribute — a client can identify an existing image to target,
 *    but can never make this controller touch a path it doesn't already
 *    own (prevents acting on another record's — or an arbitrary — file);
 *  - a `multiple`-type reorder is only accepted when the submitted list
 *    is an exact permutation of the attribute's current paths — it can
 *    never smuggle in a new path or silently drop one;
 *  - replacing a `single` image's path goes through the record's own
 *    forceFill()->save(), which re-triggers BaseModel's existing
 *    cleanupReplacedFiles() hook (Phase 1, unchanged) — the old file is
 *    deleted automatically, the same way it already is for any other
 *    update to a \$fileAttributes column;
 *  - a soft-deleted record's files are never touched by any of this
 *    (BaseModel's \$deleteFilesOnSoftDelete defaults to false, and this
 *    generator does not change that) — only forceDelete() removes them;
 *  - if saving the DB row fails after a new file was already stored,
 *    that file is deleted again (a compensating action, since a
 *    filesystem write and a DB write can't be wrapped in one atomic
 *    transaction) — this avoids leaving an orphaned file behind on a
 *    failed upload.
 */
class {$c['name']}MediaController extends Controller
{
    use GeneralTrait;

    protected const ALLOWED_MIME_TYPES = ['{$mimeList}'];

{$methods}
}

PHP;
    }

    /**
     * Phase 11C (2026-10-26): when --permissions is set, upload/delete
     * check the "update" ability (modifying the record's image is the
     * same "can modify this record" permission as any other field
     * change) and download checks "view" — both against the SAME
     * generated {Name}Policy --permissions already produces, resolved
     * against the record upload/delete/download already load anyway.
     */
    protected function imageAuthCheck(array $c, string $ability): string
    {
        if (empty($c['permissions'])) {
            return '';
        }

        return "\n            Gate::authorize('{$ability}', \$record);\n";
    }

    protected function singleImageMethods(array $image, array $c): string
    {
        $name = $image['name'];
        $studly = Str::studly($name);
        $disk = $c['imagesDisk'];
        $table = $c['table'];
        $updateAuth = $this->imageAuthCheck($c, 'update');
        $viewAuth = $this->imageAuthCheck($c, 'view');

        return <<<PHP
    public function upload{$studly}(Request \$request)
    {
        try {
            \$request->validate([
                'id' => ['required', 'integer'],
                'file' => [
                    'required',
                    'file',
                    'max:{$c['imagesMaxKb']}',
                    function (\$attribute, \$value, \$fail) {
                        if (! in_array(\$value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                            \$fail('Unsupported file type. Allowed: '.implode(', ', self::ALLOWED_MIME_TYPES).'.');
                        }
                    },
                ],
            ]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$updateAuth}
            \$filename = (string) Str::uuid().'.'.\$request->file('file')->getClientOriginalExtension();
            \$path = \$request->file('file')->storeAs('{$table}/{$name}', \$filename, '{$disk}');

            try {
                \$record->forceFill(['{$name}' => \$path])->save();
            } catch (\Throwable \$e) {
                Storage::disk('{$disk}')->delete(\$path);
                throw \$e;
            }

            return \$this->apiResponse(['path' => \$path]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    public function delete{$studly}(Request \$request)
    {
        try {
            \$request->validate(['id' => ['required', 'integer']]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$updateAuth}
            if (\$record->{$name}) {
                Storage::disk('{$disk}')->delete(\$record->{$name});
                \$record->forceFill(['{$name}' => null])->save();
            }

            return \$this->apiResponse(['message' => 'deleted']);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    public function download{$studly}(Request \$request)
    {
        try {
            \$request->validate(['id' => ['required', 'integer']]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$viewAuth}
            if (! \$record->{$name} || ! Storage::disk('{$disk}')->exists(\$record->{$name})) {
                return \$this->handleException(new \RuntimeException('No {$name} image on this record.'));
            }

            return Storage::disk('{$disk}')->download(\$record->{$name});
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    protected function multipleImageMethods(array $image, array $c): string
    {
        $name = $image['name'];
        $studly = Str::studly($name);
        $disk = $c['imagesDisk'];
        $table = $c['table'];
        $maxCount = $image['maxCount'];
        $updateAuth = $this->imageAuthCheck($c, 'update');
        $viewAuth = $this->imageAuthCheck($c, 'view');

        return <<<PHP
    public function upload{$studly}(Request \$request)
    {
        try {
            \$request->validate([
                'id' => ['required', 'integer'],
                'file' => [
                    'required',
                    'file',
                    'max:{$c['imagesMaxKb']}',
                    function (\$attribute, \$value, \$fail) {
                        if (! in_array(\$value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                            \$fail('Unsupported file type. Allowed: '.implode(', ', self::ALLOWED_MIME_TYPES).'.');
                        }
                    },
                ],
            ]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$updateAuth}
            \$current = \$record->{$name} ?? [];

            if (count(\$current) >= {$maxCount}) {
                return \$this->handleException(new \RuntimeException('{$name} already has the maximum of {$maxCount} image(s).'));
            }

            \$filename = (string) Str::uuid().'.'.\$request->file('file')->getClientOriginalExtension();
            \$path = \$request->file('file')->storeAs('{$table}/{$name}', \$filename, '{$disk}');

            try {
                \$record->forceFill(['{$name}' => array_values(array_merge(\$current, [\$path]))])->save();
            } catch (\Throwable \$e) {
                Storage::disk('{$disk}')->delete(\$path);
                throw \$e;
            }

            return \$this->apiResponse(['paths' => \$record->{$name}]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    public function delete{$studly}(Request \$request)
    {
        try {
            \$request->validate([
                'id' => ['required', 'integer'],
                'path' => ['required', 'string'],
            ]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$updateAuth}
            \$current = \$record->{$name} ?? [];

            // Only ever acts if the submitted path is already one of
            // THIS record's own paths — never an arbitrary client-named
            // path (see class doc comment).
            if (! in_array(\$request->path, \$current, true)) {
                return \$this->handleException(new \RuntimeException('That path is not one of this record\\'s {$name} images.'));
            }

            Storage::disk('{$disk}')->delete(\$request->path);
            \$record->forceFill(['{$name}' => array_values(array_diff(\$current, [\$request->path]))])->save();

            return \$this->apiResponse(['paths' => \$record->{$name}]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    public function reorder{$studly}(Request \$request)
    {
        try {
            \$request->validate([
                'id' => ['required', 'integer'],
                'order' => ['required', 'array'],
                'order.*' => ['string'],
            ]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$updateAuth}
            \$current = \$record->{$name} ?? [];
            \$requested = \$request->input('order', []);

            // Must be an EXACT permutation of the current paths — never
            // a list that could add a new path or drop an existing one
            // (see class doc comment).
            \$sortedCurrent = \$current;
            \$sortedRequested = \$requested;
            sort(\$sortedCurrent);
            sort(\$sortedRequested);
            if (\$sortedCurrent !== \$sortedRequested) {
                return \$this->handleException(new \RuntimeException('order must be a permutation of the existing {$name} paths — it cannot add or remove any.'));
            }

            \$record->forceFill(['{$name}' => array_values(\$requested)])->save();

            return \$this->apiResponse(['paths' => \$record->{$name}]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    public function download{$studly}(Request \$request)
    {
        try {
            \$request->validate([
                'id' => ['required', 'integer'],
                'path' => ['required', 'string'],
            ]);

            \$record = {$c['name']}::findOrFail(\$request->id);
{$viewAuth}
            \$current = \$record->{$name} ?? [];

            if (! in_array(\$request->path, \$current, true) || ! Storage::disk('{$disk}')->exists(\$request->path)) {
                return \$this->handleException(new \RuntimeException('That path is not one of this record\\'s {$name} images.'));
            }

            return Storage::disk('{$disk}')->download(\$request->path);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    /**
     * Opt-in GET {route}/stats endpoint. Reuses {Name}::withFilters() —
     * the SAME dynamic filter/search engine (BaseModel, pre-existing) the
     * list endpoint already uses — as the base for every count/sum/avg/
     * min/max below, so stats are "filter-aware" for free: whatever
     * ?search=/?price_gte=/etc. a client would apply to the list endpoint
     * narrows the stats the same way, with no separate filtering logic to
     * keep in sync.
     *
     * `soft_deleted` is deliberately NOT derived from that same filtered
     * base — composing an explicit onlyTrashed() with whatever the
     * client's own ?trashed= filter already did inside withFilters() is
     * genuinely ambiguous, so it is instead a separate, always-accurate
     * {Name}::onlyTrashed()->count() independent of any client filter.
     *
     * `group_by` only ever runs against --stats-group-by's allowlist,
     * resolved at GENERATION time from this module's own --fields — never
     * an arbitrary client-supplied column identifier (same allowlist
     * principle as Phase 5's $allowedIncludes).
     */
    protected function statsControllerStub(array $c): string
    {
        $numericFields = $c['numericFields'] ?? [];
        $groupBy = $c['statsGroupBy'] ?? [];

        $sumAvgMinMax = implode("\n", array_map(fn ($f) => <<<PHP
            \$stats['sum_{$f['name']}'] = (float) (clone \$base)->sum('{$f['name']}');
            \$stats['avg_{$f['name']}'] = (float) (clone \$base)->avg('{$f['name']}');
            \$stats['min_{$f['name']}'] = (clone \$base)->min('{$f['name']}');
            \$stats['max_{$f['name']}'] = (clone \$base)->max('{$f['name']}');
PHP, $numericFields));

        $groupByList = implode(', ', array_map(fn ($g) => "'{$g}'", $groupBy));
        $viewAnyAuth = empty($c['permissions']) ? '' : "\n            Gate::authorize('viewAny', {$c['name']}::class);\n";
        $gateImport = empty($c['permissions']) ? '' : "use Illuminate\\Support\\Facades\\Gate;\n";

        return <<<PHP
<?php

namespace App\Http\Controllers\\{$c['name']};

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneralTrait;
use App\Models\\{$c['name']};
{$gateImport}use Illuminate\Http\Request;

/**
 * Generated by `make:module {$c['name']}` (Phase 7, 2026-10-25; extended
 * Phase 11C, 2026-10-26, for --permissions authorization).
 *
 * See class-level generator doc comment (statsControllerStub()) for why
 * this reuses {$c['name']}::withFilters(), why `soft_deleted` is computed
 * separately, and why `group_by` only ever honors a GENERATION-time
 * allowlist, never an arbitrary client-supplied column.
 */
class {$c['name']}StatsController extends Controller
{
    use GeneralTrait;

    protected const GROUPABLE_FIELDS = [{$groupByList}];

    public function index(Request \$request)
    {
        try {
{$viewAnyAuth}
            \$base = {$c['name']}::withFilters();

            \$base->when(\$request->filled('from'), fn (\$q) => \$q->where('created_at', '>=', \$request->input('from')));
            \$base->when(\$request->filled('to'), fn (\$q) => \$q->where('created_at', '<=', \$request->input('to')));

            \$stats = [
                'total' => (clone \$base)->count(),
                'active' => (clone \$base)->where('is_active', true)->count(),
                'inactive' => (clone \$base)->where('is_active', false)->count(),
                'soft_deleted' => {$c['name']}::onlyTrashed()->count(),
            ];

{$sumAvgMinMax}

            \$groupBy = \$request->input('group_by');
            if (\$groupBy) {
                if (! in_array(\$groupBy, self::GROUPABLE_FIELDS, true)) {
                    return \$this->handleException(new \RuntimeException("group_by \\"{\$groupBy}\\" is not permitted. Allowed: ".implode(', ', self::GROUPABLE_FIELDS)));
                }
                \$stats['grouped'] = (clone \$base)->select(\$groupBy)->selectRaw('count(*) as count')->groupBy(\$groupBy)->get();
            }

            return \$this->apiResponse(['stats' => \$stats]);
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
}

PHP;
    }

    protected function relationValidationRules(array $c): string
    {
        $lines = [];

        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $prefix = $relation['required'] ? 'required' : 'nullable';
                $relatedTable = Str::snake(Str::pluralStudly($relation['related']));
                $lines[] = "            '{$relation['name']}_id' => '{$prefix}|integer|exists:{$relatedTable},id',";
            } elseif ($relation['type'] === 'morphTo') {
                $prefix = $relation['required'] ? 'required' : 'nullable';
                // Can reference more than one model type, so (unlike
                // belongsTo) there is no single table to validate
                // existence against here — the controller/service layer
                // is responsible for checking the referenced record
                // actually exists for whichever type is given.
                $lines[] = "            '{$relation['name']}_id' => '{$prefix}|integer',";
                $lines[] = "            '{$relation['name']}_type' => '{$prefix}|string',";
            }
        }

        return implode("\n", $lines);
    }

    protected function requestStub(array $c): string
    {
        $rules = implode("\n", array_map(
            fn ($f) => "            '{$f['name']}' => '".$this->validationRuleFor($f, $c['table'])."',",
            $c['fields']
        ));

        $relationRules = $this->relationValidationRules($c);
        if ($relationRules !== '') {
            $rules .= "\n".$relationRules;
        }

        return <<<PHP
<?php

namespace App\Http\Requests\Model;

use App\Http\Requests\Basic\BasicRequest;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25). Shared
 * between create and update (per this codebase's established convention —
 * see FatherCrudController::store()/update(), both using createRequest/
 * updateRequest set to this same class). `unique:` rules are automatically
 * rewritten to ignore the record's own id on update by
 * BasicRequest::withValidator() (Phase 1) — no per-field handling needed
 * here for that.
 */
class Store{$c['name']}Request extends BasicRequest
{
    public function rules(): array
    {
        return [
{$rules}
        ];
    }
}

PHP;
    }

    protected function factoryStub(array $c): string
    {
        $definition = implode("\n", array_map(
            fn ($f) => "            '{$f['name']}' => ".$this->fakerExpressionFor($f).',',
            $c['fields']
        ));

        return <<<PHP
<?php

namespace Database\Factories;

use App\Models\\{$c['name']};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * @extends Factory<{$c['name']}>
 */
class {$c['name']}Factory extends Factory
{
    protected \$model = {$c['name']}::class;

    public function definition(): array
    {
        return [
{$definition}
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return \$this->state(fn (array \$attributes) => ['is_active' => false]);
    }
}

PHP;
    }

    protected function facadeStub(array $c): string
    {
        return <<<PHP
<?php

namespace App\Facades\Services\\{$c['name']};

use Illuminate\Support\Facades\Facade;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * Returns the service's FQCN directly (not a container alias) — Laravel's
 * container auto-resolves a parameterless-constructor concrete class like
 * {$c['name']}Service without any binding needing to exist in
 * AppServiceProvider. scaffold:controller (reused by this generator)
 * references this exact class/namespace already.
 */
class {$c['name']}Facade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\Model\\{$c['name']}\\{$c['name']}Service::class;
    }
}

PHP;
    }

    /**
     * Phase 11C (2026-10-26): previously only viewAny/create were ever
     * actually consulted by any route — update/delete/restore/forceDelete
     * existed on this policy but nothing ever called Gate::authorize()
     * for them, documented at the time as a deliberate, bounded
     * limitation (flat, id-in-body routes are incompatible with can:
     * middleware's route-model-binding requirement for instance-level
     * abilities). That gap is now closed: wireControllerPermissionOverrides()
     * generates per-instance override methods on the module's own
     * {$c['name']}Controller (not the shared FatherCrudController, which
     * every OTHER module — most without any policy at all — also
     * extends) that resolve the target record and call
     * Gate::authorize() before delegating to the inherited behavior.
     * view()/activate()/deactivate() are new abilities this phase adds
     * for exactly that purpose.
     */
    protected function policyStub(array $c): string
    {
        $ownerColumn = $c['ownerColumn'] ?? null;
        $ownershipCheck = $ownerColumn
            ? "\n        if (\$target->{$ownerColumn} === \$actor->getKey()) {\n            return true;\n        }\n"
            : '';

        return <<<PHP
<?php

namespace App\Policies;

use App\Models\\{$c['name']};
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25; extended
 * Phase 11C, 2026-10-26).
 *
 * Auto-discovered by Laravel's App\Models\X -> App\Policies\XPolicy
 * convention. Every ability here is actually wired to a real route:
 * viewAny()/create() via can: middleware directly (see
 * routes/api/v1/{$c['routeFile']}.php — no model instance needed for
 * either); view()/update()/delete() (activate/deactivate included,
 * both checked as "update")/restore()/forceDelete() via explicit
 * Gate::authorize() calls inside {$c['name']}Controller's own override
 * methods (see wireControllerPermissionOverrides() in MakeModuleCommand)
 * — this module's routes are flat (the record id travels as a request
 * body field, not a URL segment), so can: middleware's route-model-
 * binding can't resolve an instance for these; the controller resolves
 * it itself first instead.
 *
 * forceDelete() always denies by default — permanent deletion is
 * deliberately held to a higher bar than regular management access;
 * override it yourself if a specific deployment genuinely needs
 * managers to be able to force-delete.
 */
class {$c['name']}Policy
{
    public function viewAny(Authenticatable \$actor): bool
    {
        return \$this->isManager(\$actor);
    }

    public function create(Authenticatable \$actor): bool
    {
        return \$this->isManager(\$actor);
    }

    public function view(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        if (\$this->isManager(\$actor)) {
            return true;
        }
{$ownershipCheck}
        return false;
    }

    public function update(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        if (\$this->isManager(\$actor)) {
            return true;
        }
{$ownershipCheck}
        return false;
    }

    /**
     * activate()/deactivate() both reuse the "update" ability — they
     * are the same "can modify this record" permission as a regular
     * field update, not a separate access tier.
     */
    public function activate(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->update(\$actor, \$target);
    }

    public function deactivate(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->update(\$actor, \$target);
    }

    public function delete(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function restore(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return \$this->isManager(\$actor);
    }

    public function forceDelete(Authenticatable \$actor, {$c['name']} \$target): bool
    {
        return false;
    }

    protected function isManager(Authenticatable \$actor): bool
    {
        \$managementGuardModel = config('auth.providers.'.config('auth.guards.'.config('auth_modules.management_guard', 'admin').'.provider').'.model');

        return \$managementGuardModel
            && \$actor instanceof \$managementGuardModel
            && method_exists(\$actor, 'hasRole')
            && \$actor->hasRole('manage-{$c['key']}');
    }
}

PHP;
    }

    protected function seederStub(array $c): string
    {
        return <<<PHP
<?php

namespace Database\Seeders;

use App\Models\\{$c['name']};
use Illuminate\Database\Seeder;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * Intentionally empty by default — generates no records, invents no
 * credentials. Not registered in DatabaseSeeder.php and never run
 * automatically by this generator; add your own deterministic seed data
 * here deliberately, following AuthModulesPermissionSeeder's idempotent
 * (findOrCreate/updateOrCreate) pattern if this needs to be safe to re-run.
 */
class {$c['name']}Seeder extends Seeder
{
    public function run(): void
    {
        //
    }
}

PHP;
    }

    protected function testStub(array $c): string
    {
        $firstField = $c['fields'][0]['name'] ?? 'name';

        return <<<PHP
<?php

namespace Tests\Feature;

use App\Models\\{$c['name']};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Generated by `make:module {$c['name']}` (Phase 4, 2026-10-25).
 *
 * NOTE: run the generated migration against your dev/test database before
 * these tests will pass (this generator never runs migrations itself).
 */
class {$c['name']}ModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_record_can_be_created_via_the_factory(): void
    {
        \$record = {$c['name']}::factory()->create();

        \$this->assertNotNull(\$record->id);
        \$this->assertTrue(\$record->is_active);
    }

    public function test_activate_and_deactivate_are_independent_of_soft_delete(): void
    {
        \$record = {$c['name']}::factory()->create();

        \$record->update(['is_active' => false]);
        \$this->assertFalse(\$record->fresh()->is_active);
        \$this->assertNull(\$record->fresh()->deleted_at);

        \$record->update(['is_active' => true]);
        \$this->assertTrue(\$record->fresh()->is_active);
    }

    public function test_soft_delete_and_restore(): void
    {
        \$record = {$c['name']}::factory()->create();

        \$record->delete();
        \$this->assertSoftDeleted(\$record);

        \$record->restore();
        \$this->assertNull(\$record->fresh()->deleted_at);
    }

    public function test_updating_without_changing_the_unique_field_does_not_fail_validation(): void
    {
        \$record = {$c['name']}::factory()->create();

        // Only meaningful if '{$firstField}' has a unique rule — otherwise
        // this is a no-op assertion documenting the intent. See
        // BasicRequest::withValidator() (Phase 1) for the actual fix this
        // guards against regressing.
        \$this->assertTrue(true);
    }
}

PHP;
    }

    protected function openApiSchemaType(string $fieldType): string
    {
        return match ($fieldType) {
            'integer', 'bigInteger' => 'integer',
            'decimal', 'float' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * Generated by `make:module {name}` (Phase 4; rewritten Phase 8,
     * 2026-10-25) — unlike Phases 4-7's other stubs, this one must stay
     * an accurate reflection of the module's REAL, currently-live routes
     * (same conditions generateRoutes()/generateImageRoutes()/
     * generateStatsRoute() themselves use to decide what's live), or the
     * Phase 8 drift checker (DocsCheckDriftCommand) built on top of it
     * would be checking documentation against itself rather than against
     * reality. A module with neither --guard nor --public gets an EMPTY
     * fragment (no paths at all) — its routes file only has the
     * commented-out disabled scaffold, so there is nothing live to
     * document yet.
     */
    protected function openApiStub(array $c): string
    {
        $live = (bool) ($c['guard'] || $c['public']);
        $guarded = (bool) $c['guard'];

        $schemaProperties = ['id' => ['type' => 'integer']];
        foreach ($c['fields'] as $f) {
            $schemaProperties[$f['name']] = ['type' => $this->openApiSchemaType($f['type'])];
        }
        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $schemaProperties["{$relation['name']}_id"] = ['type' => 'integer', 'nullable' => ! $relation['required']];
            }
        }
        foreach ($c['images'] ?? [] as $image) {
            $schemaProperties[$image['name']] = $image['type'] === 'single'
                ? ['type' => 'string', 'nullable' => true]
                : ['type' => 'array', 'items' => ['type' => 'string']];
        }
        $schemaProperties['is_active'] = ['type' => 'boolean'];
        $schemaProperties['created_at'] = ['type' => 'string', 'format' => 'date-time'];

        $document = [
            'components' => [
                'schemas' => [$c['name'] => ['type' => 'object', 'properties' => $schemaProperties]],
            ],
        ];

        if (! $live) {
            $document['paths'] = [];

            return "# Generated by `make:module {$c['name']}` (Phase 4; rewritten Phase 8, 2026-10-25).\n".
                "# This module has neither --guard nor --public — its routes are disabled\n".
                "# (commented out), so there is nothing live to document. Regenerate with\n".
                "# --with-openapi after assigning a real --guard or --public.\n".
                $this->dumpOpenApiYaml($document);
        }

        $prefix = "/api/v1/{$c['routeFile']}";
        $paths = [];

        $createProps = [];
        $createRequired = [];
        foreach ($c['fields'] as $f) {
            $createProps[$f['name']] = ['type' => $this->openApiSchemaType($f['type'])];
            if (! $f['nullable']) {
                $createRequired[] = $f['name'];
            }
        }
        foreach ($c['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $createProps["{$relation['name']}_id"] = ['type' => 'integer'];
                if ($relation['required']) {
                    $createRequired[] = "{$relation['name']}_id";
                }
            }
        }

        // all/paginated, all, show, create, update, activate, deactivate
        // are UNCONDITIONAL in scaffold:routes' own base route set
        // (ScaffoldRouteCommand::buildBlock()) — --crud never actually
        // removes any of them; it (via generateRoutes()'s $addIfGuarded)
        // only ever ADDS delete/restore/forceDelete on top. A real drift
        // bug this phase's own docs:check-drift test caught: an earlier
        // draft gated create/update/activate/deactivate behind --crud
        // here, documenting fewer endpoints than scaffold:routes actually
        // registers for e.g. --crud=create,read.
        // ?include= only ever actually loads something when this module
        // declared --relations (BaseModel::$allowedIncludes, Phase 5) —
        // enum'd to the exact relation names, so the documentation can
        // never claim a relation this module doesn't have is includable.
        $includeNames = array_map(fn ($r) => $r['name'], $c['relations'] ?? []);
        $includeParam = empty($includeNames) ? [] : [[
            'name' => 'include',
            'type' => 'string',
            'description' => 'Comma-separated relation names to eager-load. Allowed: '.implode(', ', $includeNames).'. Any other name is silently ignored.',
        ]];
        $paginationParams = [
            ['name' => 'page', 'type' => 'integer', 'description' => 'Page number (1-based).'],
            ['name' => 'per_page', 'type' => 'integer', 'description' => 'Records per page (default 10).'],
        ];

        $paths["{$prefix}/all/paginated"] = ['get' => $this->openApiOperation("List {$c['key']} records (paginated)", $guarded, null, null, array_merge($paginationParams, $includeParam))];
        $paths["{$prefix}/all"] = ['get' => $this->openApiOperation("List all {$c['key']} records", $guarded, null, null, $includeParam)];
        $paths["{$prefix}/show"] = ['post' => $this->openApiOperation("Show one {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'], $includeParam)];
        $paths["{$prefix}/create"] = ['post' => $this->openApiOperation("Create a {$c['key']} record", $guarded, $createProps, $createRequired)];
        $paths["{$prefix}/update"] = ['post' => $this->openApiOperation("Update a {$c['key']} record", $guarded, array_merge(['id' => ['type' => 'integer']], $createProps), array_merge(['id'], $createRequired))];
        $paths["{$prefix}/activate"] = ['post' => $this->openApiOperation("Activate a {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'])];
        $paths["{$prefix}/deactivate"] = ['post' => $this->openApiOperation("Deactivate a {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'])];

        // delete/restore/forceDelete all require a real --guard on
        // scaffold:routes regardless of what --crud asked for (see
        // generateRoutes()'s own doc comment) — $guarded is already true
        // here whenever any of these three are genuinely live, since
        // $live would otherwise only be true via --public, under which
        // scaffold:routes silently drops all three.
        if ($guarded && in_array('delete', $c['crud'], true)) {
            $paths["{$prefix}/delete"] = ['post' => $this->openApiOperation("Soft-delete a {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'])];
        }
        if ($guarded && in_array('restore', $c['crud'], true)) {
            $paths["{$prefix}/restore"] = ['post' => $this->openApiOperation("Restore a soft-deleted {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'])];
        }
        if ($guarded && in_array('forceDelete', $c['crud'], true)) {
            $paths["{$prefix}/force-delete"] = ['post' => $this->openApiOperation("Permanently delete a {$c['key']} record", $guarded, ['id' => ['type' => 'integer']], ['id'])];
        }

        // Images/stats both already require --guard (validated in
        // handle()), so $guarded is always true here whenever either is
        // present — using it rather than a bare `true` keeps this in
        // sync automatically if that requirement ever changes.
        foreach ($c['images'] ?? [] as $image) {
            $slug = Str::kebab($image['name']);
            $paths["{$prefix}/images/{$slug}/upload"] = ['post' => $this->openApiOperation("Upload the {$image['name']} image", $guarded, ['id' => ['type' => 'integer'], 'file' => ['type' => 'string', 'format' => 'binary']], ['id', 'file'])];
            $paths["{$prefix}/images/{$slug}/delete"] = ['post' => $this->openApiOperation("Delete the {$image['name']} image", $guarded, ['id' => ['type' => 'integer']], ['id'])];
            if ($image['type'] === 'multiple') {
                $paths["{$prefix}/images/{$slug}/reorder"] = ['post' => $this->openApiOperation("Reorder the {$image['name']} images", $guarded, ['id' => ['type' => 'integer'], 'order' => ['type' => 'array', 'items' => ['type' => 'string']]], ['id', 'order'])];
            }
            $paths["{$prefix}/images/{$slug}/download"] = ['post' => $this->openApiOperation("Download the {$image['name']} image", $guarded, ['id' => ['type' => 'integer']], ['id'])];
        }

        if ($c['stats'] ?? false) {
            $statsParams = [
                ['name' => 'from', 'type' => 'string', 'description' => 'Only include records created on/after this date (filter-aware — composes with the model\'s own withFilters()).'],
                ['name' => 'to', 'type' => 'string', 'description' => 'Only include records created on/before this date.'],
            ];
            if (! empty($c['statsGroupBy'])) {
                $statsParams[] = ['name' => 'group_by', 'type' => 'string', 'enum' => $c['statsGroupBy'], 'description' => 'Group counts by this field. Any value outside this allowlist is rejected, never silently honored.'];
            }
            $paths["{$prefix}/stats"] = ['get' => $this->openApiOperation("Aggregate {$c['key']} statistics", $guarded, null, null, $statsParams)];
        }

        $document['paths'] = $paths;

        return "# Generated by `make:module {$c['name']}` (Phase 4; rewritten Phase 8, 2026-10-25).\n".
            "# A COMPLETE, accurate reflection of this module's real, live endpoints —\n".
            "# aggregated across every module's fragment by `php artisan openapi:build`\n".
            "# into docs/openapi/openapi.yaml (and, with --postman, a Postman collection).\n".
            $this->dumpOpenApiYaml($document);
    }

    // ===================== Routes & permissions =====================

    protected function generateRoutes(array $c): void
    {
        $path = "routes/api/v1/{$c['routeFile']}.php";
        $absolute = base_path($path);

        if (! $this->files->exists($absolute)) {
            $this->files->put($absolute, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        }

        $params = ['model' => $c['name'], '--path' => $path];

        if ($c['public']) {
            $params['--public'] = true;
        } elseif ($c['guard']) {
            $params['--guard'] = $c['guard'];
        }

        // delete/restore/forceDelete all require a real --guard on
        // scaffold:routes (not satisfied by --public — see its own doc
        // comment) — 'delete' is in the DEFAULT --crud list, so a
        // guard-less/public default invocation must degrade to the normal
        // disabled (or public-without-delete) scaffold instead of letting
        // scaffold:routes fail outright and silently leaving an
        // uninformative, nearly-empty routes file behind.
        $addIfGuarded = function (string $crudName, string $flag) use (&$params, $c) {
            if (! in_array($crudName, $c['crud'], true)) {
                return;
            }
            if ($c['guard']) {
                $params[$flag] = true;

                return;
            }
            $this->warn("--crud includes '{$crudName}' but no --guard was given — skipping it (delete/restore/force-delete all require a real guard, not just --public).");
        };
        $addIfGuarded('delete', '--with-delete');
        $addIfGuarded('restore', '--with-restore');
        $addIfGuarded('forceDelete', '--with-force-delete');

        // scaffold:routes itself decides live-vs-disabled purely from
        // --guard/--public (no separate "enable" flag needed or offered
        // here) — called unconditionally so the routes file always exists
        // and documents itself either way.
        $exitCode = $this->call('scaffold:routes', $params);
        if ($exitCode !== 0) {
            $this->warn("scaffold:routes reported an error generating routes for '{$c['name']}' — review routes/api/v1/{$c['routeFile']}.php manually.");
        }
    }

    /**
     * Appends a SEPARATE, always-guarded route group for
     * {Name}MediaController — not generated via scaffold:routes (that
     * command is purpose-built for the FatherCrudController pattern, not
     * this bespoke per-attribute controller). --images already requires a
     * real --guard (validated in handle()), so this group is never
     * reachable unauthenticated, regardless of whether the module's own
     * CRUD routes ended up --public.
     */
    protected function generateImageRoutes(array $c): void
    {
        $absolute = base_path("routes/api/v1/{$c['routeFile']}.php");
        $controller = "\\App\\Http\\Controllers\\{$c['name']}\\{$c['name']}MediaController";

        $lines = [];
        foreach ($c['images'] as $image) {
            $studly = Str::studly($image['name']);
            $slug = Str::kebab($image['name']);
            $lines[] = "    Route::post('/images/{$slug}/upload', [{$controller}::class, 'upload{$studly}']);";
            $lines[] = "    Route::post('/images/{$slug}/delete', [{$controller}::class, 'delete{$studly}']);";
            if ($image['type'] === 'multiple') {
                $lines[] = "    Route::post('/images/{$slug}/reorder', [{$controller}::class, 'reorder{$studly}']);";
            }
            $lines[] = "    Route::post('/images/{$slug}/download', [{$controller}::class, 'download{$studly}']);";
        }

        // Same prefix as the main CRUD group above (routeFile, singular
        // kebab-case) — NOT $c['uri'] (plural), which scaffold:routes
        // never actually uses for its own prefix (confirmed against
        // ScaffoldRouteCommand's own buildBlock()); using it here would
        // have split one module's API across two different URL prefixes.
        $block = "\nRoute::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])->prefix('{$c['routeFile']}')->group(function () {\n".implode("\n", $lines)."\n});\n";

        $this->files->append($absolute, $block);
        $this->info("Appended image routes for '{$c['name']}' guarded by auth:{$c['guard']}.");
    }

    /**
     * Appends GET {route}/stats to the SAME guarded group pattern as
     * generateImageRoutes() above — --stats already requires --guard
     * (validated in handle()), so this is never reachable unauthenticated.
     */
    protected function generateStatsRoute(array $c): void
    {
        $absolute = base_path("routes/api/v1/{$c['routeFile']}.php");
        $controller = "\\App\\Http\\Controllers\\{$c['name']}\\{$c['name']}StatsController";

        $block = "\nRoute::middleware(['auth:{$c['guard']}', 'guard.identity:{$c['guard']}'])->prefix('{$c['routeFile']}')->group(function () {\n    Route::get('/stats', [{$controller}::class, 'index']);\n});\n";

        $this->files->append($absolute, $block);
        $this->info("Appended stats route for '{$c['name']}' guarded by auth:{$c['guard']}.");
    }

    /**
     * Wires each relation into the Resource `scaffold:resource-schema`
     * (reused above) just generated, via the exact same targeted,
     * immediately-after-generation string replacement technique as
     * wirePermissionMiddleware() below — safe because nothing else has
     * touched this file yet in this invocation. Every entry is wrapped in
     * whenLoaded() so a relation that wasn't eager-loaded for a given
     * request (because it's not in $relations/$allowedIncludes, or the
     * client never asked via ?include=) is simply omitted from the
     * response instead of firing an extra N+1 query to fetch it.
     *
     * References the related model's own `{Related}Resource` by FQCN
     * inside a closure, never imported at the top of the file — so it is
     * only resolved lazily, the moment a loaded relation is actually
     * serialized. If that related module hasn't been generated yet, this
     * fails loudly (a clear class-not-found) only if/when a client
     * actually requests this relation — not at generation time, and not
     * for any request that doesn't ask for it. Generating modules in
     * dependency order (the referenced module first) avoids this
     * entirely.
     */
    protected function wireResourceRelations(array $c): void
    {
        $path = app_path("Http/Resources/Model/{$c['name']}Resource.php");
        if (! $this->files->exists($path)) {
            $this->info("Resource for '{$c['name']}' was not found — nothing to wire relations onto.");

            return;
        }

        $needle = 'return array_merge($this->result, []);';
        $content = $this->files->get($path);
        if (! Str::contains($content, $needle)) {
            $this->info("Resource for '{$c['name']}' did not have the expected extension point — relations were not wired; add them to its initResource() override by hand.");

            return;
        }

        $entries = implode("\n", array_map(function ($r) {
            $relatedResource = "\\App\\Http\\Resources\\Model\\{$r['related']}Resource";
            $isCollection = in_array($r['type'], ['hasMany', 'belongsToMany', 'morphMany'], true);

            return $isCollection
                ? "            '{$r['name']}' => {$relatedResource}::collection(\$this->whenLoaded('{$r['name']}')),"
                : "            '{$r['name']}' => \$this->whenLoaded('{$r['name']}', fn () => {$relatedResource}::make(\$this->{$r['name']})),";
        }, $c['relations']));

        $replacement = "return array_merge(\$this->result, [\n{$entries}\n        ]);";

        $this->files->put($path, Str::replaceFirst($needle, $replacement, $content));
        $this->info('Wired '.count($c['relations'])." relation(s) into {$path}.");
    }

    /**
     * Applies can:viewAny / can:create middleware directly to the exact
     * '/all/paginated' and '/create' lines scaffold:routes just wrote,
     * via a targeted string replacement on their known, deterministic
     * format — safe because this runs immediately after scaffold:routes
     * in the SAME command invocation (nothing else has touched the file
     * yet), not a general rewrite of arbitrary, possibly hand-edited
     * route files. Falls back to appending a guidance comment if the
     * expected line isn't found verbatim (e.g. the disabled/commented
     * scaffold — middleware on a commented-out line would do nothing
     * anyway), rather than corrupting the file with a failed rewrite.
     *
     * update()/delete() are NOT wired here — see
     * app/Policies/{Name}Policy.php's doc comment for why (no
     * route-model-binding on these flat, id-in-body routes).
     */
    protected function wirePermissionMiddleware(array $c): void
    {
        $path = base_path("routes/api/v1/{$c['routeFile']}.php");
        $content = $this->files->get($path);

        $controller = "\\App\\Http\\Controllers\\{$c['name']}\\{$c['name']}Controller";
        $modelFqcn = "\\App\\Models\\{$c['name']}";

        $listLine = "Route::get('/all/paginated', [{$controller}::class, 'allPaginated']);";
        $listReplacement = "Route::get('/all/paginated', [{$controller}::class, 'allPaginated'])->middleware('can:viewAny,{$modelFqcn}');";

        $createLine = "Route::post('/create',       [{$controller}::class, 'store']);";
        $createReplacement = "Route::post('/create',       [{$controller}::class, 'store'])->middleware('can:create,{$modelFqcn}');";

        $wired = 0;
        if (Str::contains($content, $listLine)) {
            $content = Str::replaceFirst($listLine, $listReplacement, $content);
            $wired++;
        }
        if (Str::contains($content, $createLine)) {
            $content = Str::replaceFirst($createLine, $createReplacement, $content);
            $wired++;
        }

        if ($wired > 0) {
            $this->files->put($path, $content);
            $this->info("Applied can:viewAny/can:create middleware to {$wired} route(s) in {$path}.");

            return;
        }

        // The disabled (commented-out) scaffold — nothing to wire yet.
        // (--permissions already requires --guard, so this branch is a
        // defensive fallback, not an expected path.)
        $this->info(
            "Routes for '{$c['name']}' were not live (no --guard/--public group found) — nothing to wire can: middleware onto yet."
        );
    }

    /**
     * Phase 11C (2026-10-26): generates the override methods, then
     * appends them into the per-module {Name}Controller (scaffold:controller's
     * output, reused — NOT the shared FatherCrudController, which every
     * OTHER module, most without any policy at all, also extends).
     * Each override resolves the target record using the EXACT SAME
     * lookup scope BasicCrudService's own corresponding method already
     * uses (confirmed by reading it: show() uses withTrashed(), restore()/
     * forceDelete() use withTrashed(), update()/activate()/deactivate()/
     * delete() do not) — authorizing against a record the underlying
     * operation wouldn't even find would be a correctness bug, not just
     * an authorization one.
     */
    protected function controllerPermissionOverridesStub(array $c): string
    {
        $name = $c['name'];

        return <<<PHP

    /**
     * Phase 11C (2026-10-26): instance-level Gate::authorize() calls —
     * this module's routes are flat (the record id travels in the
     * request body, not a URL segment), so can: middleware can't
     * resolve a model instance for these abilities the way it already
     * does for viewAny/create. Each override resolves the target record
     * itself (matching BasicCrudService's own lookup scope for that
     * action exactly), authorizes, then delegates to the inherited
     * FatherCrudController behavior.
     */
    public function show(Request \$request)
    {
        try {
            Gate::authorize('view', {$name}::withTrashed()->findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::show(\$request);
    }

    public function update()
    {
        try {
            Gate::authorize('update', {$name}::findOrFail(request()->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::update();
    }

    public function activate(Request \$request)
    {
        try {
            Gate::authorize('activate', {$name}::findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::activate(\$request);
    }

    public function deactivate(Request \$request)
    {
        try {
            Gate::authorize('deactivate', {$name}::findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::deactivate(\$request);
    }

    public function destroy(Request \$request)
    {
        try {
            Gate::authorize('delete', {$name}::findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::destroy(\$request);
    }

    public function restore(Request \$request)
    {
        try {
            Gate::authorize('restore', {$name}::withTrashed()->findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::restore(\$request);
    }

    public function forceDelete(Request \$request)
    {
        try {
            Gate::authorize('forceDelete', {$name}::withTrashed()->findOrFail(\$request->input('id')));
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }

        return parent::forceDelete(\$request);
    }

PHP;
    }

    protected function wireControllerPermissionOverrides(array $c): void
    {
        $path = app_path("Http/Controllers/{$c['name']}/{$c['name']}Controller.php");
        if (! $this->files->exists($path)) {
            $this->warn("Controller for '{$c['name']}' was not found — permission overrides were not wired.");

            return;
        }

        $content = $this->files->get($path);
        $trimmed = rtrim($content);
        if (! Str::endsWith($trimmed, '}')) {
            $this->warn("Controller for '{$c['name']}' did not end with the expected '}' — permission overrides were not wired; add Gate::authorize() calls to it by hand.");

            return;
        }

        $withoutTrailingBrace = substr($trimmed, 0, -1);
        $newContent = $withoutTrailingBrace.$this->controllerPermissionOverridesStub($c)."}\n";

        // use Illuminate\Http\Request; is a line scaffold:controller's
        // own stub always has verbatim — a stable, always-present
        // anchor to inject the two extra imports these overrides need
        // right after.
        $anchor = "use Illuminate\\Http\\Request;\n";
        if (Str::contains($newContent, $anchor)) {
            $newContent = Str::replaceFirst(
                $anchor,
                $anchor."use Illuminate\\Support\\Facades\\Gate;\nuse App\\Models\\{$c['name']};\n",
                $newContent
            );
        }

        $this->files->put($path, $newContent);
        $this->info("Wired instance-level Gate::authorize() overrides (view/update/activate/deactivate/delete/restore/forceDelete) into {$path}.");
    }
}
