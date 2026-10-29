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

    /**
     * Phase 5 (2026-10-25): merges this service's own fixed `$relations`
     * (always loaded, server-decided) with the CLIENT's `?include=`
     * request — but only the part of it that intersects the model's own
     * `$allowedIncludes` allowlist (see BaseModel::$allowedIncludes). A
     * relation the client names that isn't in that allowlist is silently
     * dropped, never passed to with()/load() — this is what keeps
     * `?include=` from ever exposing a relation the model didn't
     * explicitly opt into, and from letting a client force unrestricted
     * eager loading of anything it likes.
     */
    protected function resolveRelations(?Request $request = null): array
    {
        $request ??= request();

        $requested = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('include', ''))
        ), fn ($name) => $name !== ''));

        if (empty($requested)) {
            return $this->relations;
        }

        $allowed = method_exists($this->model, 'getAllowedIncludes')
            ? (new $this->model)->getAllowedIncludes()
            : [];

        $safe = array_values(array_intersect($requested, $allowed));

        return array_values(array_unique(array_merge($this->relations, $safe)));
    }

    protected function allQuery(?Request $request = null) : object
    {
        return $this->model::withFilters()
            ->with($this->resolveRelations($request))
            ->orderBy('created_at', 'desc');
    }

    /** ✅ add: pureAll used by FatherCrudController */
    public function pureAll(Request $request): mixed
    {
        $data = $this->allQuery($request)->get();
        return $this->resource::collection($data);
    }

    public function all(Request $request) : mixed
    {
        $data = $this->allQuery($request)->get();
        return $this->resource::collection($data);
    }

    public function allPaginated(Request $request): mixed {
        $data = $this->allQuery($request)->paginate(
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
        $this->object = $this->model::with($this->resolveRelations($request))
            ->withTrashed()
            ->withCount($this->countRelations)
            ->findOrFail($request->id);

        return $this->resource::make($this->object);
    }

    public function create(BasicRequest $request): mixed {
        $this->object = $this->model::create($request->validated());
        return $this->resource::make($this->object->load($this->relations));
    }

    public function update(BasicRequest $request): mixed {
        $this->object =  $this->model::with($this->relations)->findOrFail($request->id);
        $this->object->update($request->validated());
        return $this->resource::make($this->object);
    }

    /**
     * Activate (`is_active = true`) WITHOUT touching `deleted_at`.
     *
     * Phase 1 (2026-10-25): previously this method called `restore()` on a
     * `withTrashed()` lookup, conflating "active/inactive" with
     * "soft-deleted/restored". That is a behavior change for any future
     * caller that relied on the old restore-based semantics — see
     * docs/starter-roadmap.md Phase 1 "Backward-compatibility notes".
     * Deliberately does NOT use withTrashed(): a soft-deleted record must be
     * restore()'d first before its is_active flag is toggled.
     */
    public function activate(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->update(['is_active' => true]);
    }

    /**
     * Deactivate (`is_active = false`) WITHOUT touching `deleted_at`.
     *
     * Phase 1 (2026-10-25): previously this method called the Eloquent
     * `delete()` (soft delete) directly, conflating "inactive" with
     * "soft-deleted". See deactivate()'s sibling note on activate() above.
     */
    public function deactivate(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->update(['is_active' => false]);
    }

    /**
     * Soft-delete (`deleted_at` set), independent of `is_active`.
     *
     * Phase 1 (2026-10-25): previously this method called `forceDelete()`
     * (a permanent, unrecoverable delete) with no separate authorization
     * gate. It now performs the reversible Eloquent soft delete. Use
     * forceDelete() below for the permanent, explicitly-named operation.
     */
    public function delete(Request $request): bool {
        $this->object = $this->model::findOrFail($request->id);
        return $this->object->delete();
    }

    /**
     * Restore (clear `deleted_at`) a soft-deleted record, independent of
     * `is_active`. New in Phase 1 (2026-10-25) — previously this concept
     * was folded into activate().
     */
    public function restore(Request $request): bool {
        $this->object = $this->model::withTrashed()->findOrFail($request->id);
        return $this->object->restore();
    }

    /**
     * Permanently delete a record. New, SEPARATE, explicitly-named method
     * (not aliased to delete()) added in Phase 1 (2026-10-25). Not wired to
     * any route in this phase — no permission system exists yet to gate it,
     * consistent with FatherCrudController::destroy() already being
     * unrouted today. Only call this from trusted, explicitly-authorized
     * call sites once such a gate exists (Phase 2+).
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
