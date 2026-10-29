<?php

namespace App\Console\Commands\CodeBasics;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateBasicCrudServiceCommand extends Command
{
    protected $signature = 'make:basic-crud-service
     {--soft-deletes : Deprecated/no-op, kept for backward CLI compatibility. See note below.}';
    protected $description = 'Generate the BasicCrudService in app/Services/Basic';

    public function handle(Filesystem $files)
    {
        $directory = app_path('Services/Basic');
        if (! $files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0755, true);
            $this->info("Created directory: {$directory}");
        }

        $filePath = $directory . '/BasicCrudService.php';
        if ($files->exists($filePath)) {
            $this->info("Overwriting existing Controller at: {$filePath}");
        }

        if ($this->option('soft-deletes')) {
            $this->warn(
                "--soft-deletes is deprecated and now a no-op (Phase 1, 2026-10-25): ".
                "the generated service always separates is_active from deleted_at ".
                "and includes activate/deactivate/delete/restore/forceDelete as five ".
                "distinct methods. There is no longer a 'status column only' variant, ".
                "because BaseModel/BaseAuthModel both unconditionally use SoftDeletes, ".
                "so soft-delete support is always present."
            );
        }

        $files->put($filePath, $this->stub());
        $this->info("Generated BasicCrudService at: {$filePath}");
        return 0;
    }

    /**
     * Phase 1 (2026-10-25): converged generator template.
     *
     * Previously this command had two diverging stub variants
     * (withTrashedStub() using restore()/delete()/forceDelete() for
     * activate/deactivate/delete, and withStatus() using
     * update(['status' => ...]) for activate/deactivate and a plain
     * delete() for delete) — neither of which matched the checked-in
     * app/Services/Basic/BasicCrudService.php, and which disagreed with
     * EACH OTHER. All three sources of truth are now reconciled into this
     * single stub, which mirrors the real BasicCrudService.php contract
     * exactly:
     *   - activate()/deactivate() toggle `is_active` only (never touch
     *     `deleted_at`);
     *   - delete() soft-deletes (`deleted_at` set);
     *   - restore() clears `deleted_at` (independent of `is_active`);
     *   - forceDelete() is a SEPARATE, explicitly-named permanent delete,
     *     never aliased to delete().
     *
     * The generated model is expected to have both an `is_active` column
     * and a `deleted_at` column (see the Phase 1 additive migration
     * database/migrations/2026_10_25_120000_add_lifecycle_columns_to_users_tests_categories_tables.php
     * for the pattern to follow when adding these columns to a new table).
     */
    protected function stub(): string
    {
        return <<<'PHP'
<?php

namespace App\Services\Basic;

use App\Http\Requests\Basic\BasicRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class BasicCrudService {

    protected $modelColumnsService;

    protected $model;
    protected $resource;
    protected $object;
    protected $relations = [];
    protected $countRelations = [];

    public function __construct() {
        $this->setVariables();
    }

    protected abstract function setVariables() : void;

    protected function allQuery() : object
    {
        return $this->model::withFilters() //The With Filter Method Is Scope For Filter In The Model
            ->with($this->relations)
            ->withCount($this->countRelations)
            ->orderBy('created_at', 'desc');
    }

    public function pureAll(Request $request): mixed
    {
        $data = $this->allQuery()->get();
        return $this->resource::collection($data);
    }

    public function all(Request $request) : mixed
    {
        $data = $this->allQuery()->get();
        return $this->resource::collection($data);
    }

    public function allPaginated(Request $request): mixed {

        $data = $this->allQuery()->paginate(
            $request->input('per_page', 10),
            ['*'],
            'page',
            $request->input('page', 1)
        );

        return [
            Str::plural(strtolower(class_basename($this->model))) => $this->resource::collection($data),
            'current_page' => $data->currentPage(),
            'next_page' => $data->nextPageUrl(),
            'previous_page' => $data->previousPageUrl(),
            'total_pages' => $data->lastPage(),
        ];

    }

    public function show(Request $request): mixed {

        $this->object = $this->model::withTrashed()->with(
            $this->relations
        )->withCount($this->countRelations)->findOrFail($request->id);

        return $this->resource::make($this->object);

    }

    public function create(BasicRequest $request): mixed {

        $this->object = $this->model::create($request->validated());
        return $this->resource::make(
            $this->object->load($this->relations)->loadCount($this->countRelations)
        );

    }

    public function update(BasicRequest $request): mixed {

        $this->object = $this->model::with($this->relations)->findOrFail($request->id);
        $this->object->update(
            $request->validated()
        );

        return $this->resource::make($this->object->load($this->relations)->loadCount($this->countRelations));

    }

    /**
     * Activate (`is_active = true`) WITHOUT touching `deleted_at`.
     * Deliberately does NOT use withTrashed(): a soft-deleted record must be
     * restore()'d first before its is_active flag is toggled.
     */
    public function activate(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->update(['is_active' => true]);
    }

    /**
     * Deactivate (`is_active = false`) WITHOUT touching `deleted_at`.
     */
    public function deactivate(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->update(['is_active' => false]);
    }

    /**
     * Soft-delete (`deleted_at` set), independent of `is_active`.
     * Use forceDelete() below for the permanent, explicitly-named operation.
     */
    public function delete(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->delete();
    }

    /**
     * Restore (clear `deleted_at`) a soft-deleted record, independent of
     * `is_active`.
     */
    public function restore(Request $request): bool {
        $this->object = $this->model::withTrashed()->findOrFail($request->id);
        return $this->object->restore();
    }

    /**
     * Permanently delete a record. SEPARATE, explicitly-named method (not
     * aliased to delete()). Not wired to any route by this generator — wire
     * it only behind an explicit authorization gate.
     */
    public function forceDelete(Request $request): bool {
        $this->object = $this->model::withTrashed()->findOrFail($request->id);
        return $this->object->forceDelete();
    }

    public function getObject() : object
    {
        return $this->object;
    }

}
PHP;
    }
}
