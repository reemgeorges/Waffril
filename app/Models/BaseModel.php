<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

class BaseModel extends Model
{
    use HasFactory, HasTranslations, SoftDeletes;

    /** Primary key config */
    public $incrementing = true;

    protected $keyType = 'integer';

    /** Spatie translatable attributes */
    protected $translatable = [];

    /** Global LIKE search columns (used by ?search=) */
    protected $search = [];

    /** Optional: columns to export */
    protected $excel = [];

    /**
     * Legacy declarative filters (kept for backward-compat).
     */
    protected $filters = [];

    /**
     * Simple per-model filter shortcuts (optional).
     *
     * Example:
     * protected array $filterable = [
     *     'is_sold' => 'bool',
     *     'name'    => 'like',
     *     'brand_id'=> 'int',
     * ];
     * gives you:
     *   ?is_sold=1
     *   ?name=iphone
     *   ?brand_id=12
     */
    protected array $filterable = [];

    /* ================== Dynamic filtering/sorting (NEW) ================== */

    /**
     * Master flag: allow dynamic filtering/sorting or not.
     */
    protected bool $enableDynamicFilters = true;

    /**
     * Whitelist of columns allowed for dynamic filtering/sorting.
     *
     * - Direct columns: 'price', 'brand_id', 'created_at'
     * - Relation columns: 'details.attribute_id', 'attributes.name', 'attributeValues.value'
     *
     * If it's not in this whitelist → request param for it will be ignored.
     */
    protected array $dynamicFilterColumns = [];

    protected array $treeFilterModels = [];

    /**
     * Allowed operators for dynamic filters:
     * eq     -> column = value
     * neq    -> column != value
     * like   -> column LIKE %value%
     * nlike  -> column NOT LIKE %value%
     * in     -> column IN (a,b,c)
     * nin    -> column NOT IN (a,b,c)
     * gt/gte/lt/lte
     * between -> column BETWEEN x AND y
     * bool   -> column = true/false
     * null   -> IS NULL / IS NOT NULL
     */
    protected array $allowedOps = [
        'eq', 'neq',
        'like', 'nlike',
        'in', 'nin',
        'gt', 'gte', 'lt', 'lte',
        'between',
        'bool',
        'null',
    ];

    /**
     * Which columns are allowed for sorting (?sort=price,-created_at).
     * If empty → we'll reuse $dynamicFilterColumns for sorting.
     */
    protected array $sortableColumns = [];

    /**
     * Phase 5 (2026-10-25): client-requested eager-loading allowlist.
     *
     * Empty by default — `?include=` is a no-op until a model opts in by
     * listing the relation names here it is safe to eager-load on request.
     * BasicCrudService::resolveRelations() intersects the client's
     * comma-separated `?include=` against this list before ever passing
     * anything to with()/load() — the request never names a relation the
     * query actually uses unless it is also in this allowlist, which is
     * what prevents unauthorized relationship exposure and unrestricted
     * eager loading (every relation not listed here is simply ignored).
     *
     * Example:
     * protected array $allowedIncludes = ['category', 'images'];
     */
    protected array $allowedIncludes = [];

    public function getAllowedIncludes(): array
    {
        return $this->allowedIncludes;
    }

    /* ================== File management (shared) ================== */

    // e.g. ['main_image' => 'single', 'images' => 'array']
    protected array $fileAttributes = [];

    protected string $fileDisk = 'public';

    protected bool $deleteFilesOnSoftDelete = false;

    protected static function booted(): void
    {
        // delete replaced files on update
        static::updating(function (self $model) {
            $model->cleanupReplacedFiles();
        });

        // delete files on delete if configured
        static::deleting(function (self $model) {
            if (! $model->usesSoftDeletes() || $model->deleteFilesOnSoftDelete) {
                $model->cleanupAllFiles();
            }
        });

        // delete files on force delete
        static::forceDeleted(function (self $model) {
            $model->cleanupAllFiles();
        });
    }

    protected function usesSoftDeletes(): bool
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            if ($trait === SoftDeletes::class) {
                return true;
            }
        }

        return false;
    }

    /* ===== File helpers ===== */

    /**
     * Phase 11B (2026-10-26): real bug fix. This runs on the `updating`
     * model event, which fires BEFORE the actual UPDATE statement is
     * sent — and, if the save() happens inside a caller's
     * DB::transaction() that later rolls back for some unrelated reason,
     * well before that transaction's outcome is known. Deleting the
     * file immediately here meant a rolled-back transaction left the DB
     * row pointing at its OLD path while the OLD FILE was already gone
     * from disk — filesystem writes can't be rolled back the way SQL
     * can. Every actual deletion is now deferred to
     * DB::afterCommit(), which Laravel runs immediately if there is no
     * active transaction (the common case — no behavior change there)
     * and only after a genuine commit otherwise, so a rollback leaves
     * both the DB row AND the file exactly as they were.
     */
    protected function cleanupReplacedFiles(): void
    {
        if (empty($this->fileAttributes)) {
            return;
        }

        foreach ($this->fileAttributes as $attr => $type) {
            $disk = $this->fileDisk;

            if ($type === 'single') {
                $old = $this->normalizeStoragePath($this->getOriginal($attr));
                $new = $this->normalizeStoragePath($this->{$attr});
                if ($old && $old !== $new) {
                    DB::afterCommit(fn () => $this->deleteFileQuietly($old, $disk));
                }
            } elseif ($type === 'array') {
                $oldArr = $this->toArrayOfPaths($this->getOriginal($attr));
                $newArr = $this->toArrayOfPaths($this->{$attr});
                $toDelete = array_diff($oldArr, $newArr);
                foreach ($toDelete as $path) {
                    DB::afterCommit(fn () => $this->deleteFileQuietly($path, $disk));
                }
            }
        }
    }

    /**
     * Same after-commit reasoning as cleanupReplacedFiles() above — this
     * runs on `deleting` (before the delete/soft-delete query) and
     * `forceDeleted` (right after the delete query, but not necessarily
     * after any transaction wrapping it has actually committed).
     */
    protected function cleanupAllFiles(): void
    {
        if (empty($this->fileAttributes)) {
            return;
        }

        foreach ($this->fileAttributes as $attr => $type) {
            $disk = $this->fileDisk;

            if ($type === 'single') {
                $path = $this->normalizeStoragePath($this->{$attr});
                DB::afterCommit(fn () => $this->deleteFileQuietly($path, $disk));
            } elseif ($type === 'array') {
                foreach ($this->toArrayOfPaths($this->{$attr}) as $path) {
                    DB::afterCommit(fn () => $this->deleteFileQuietly($path, $disk));
                }
            }
        }
    }

    protected function normalizeStoragePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $path = trim($path);

        // public URLs → strip /storage/
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsed = parse_url($path);
            $p = $parsed['path'] ?? '';
            $p = preg_replace('#^/storage/#', '', ltrim($p, '/'));

            return $p ?: null;
        }

        // "storage/foo.jpg" → "foo.jpg"
        if (str_starts_with($path, 'storage/')) {
            return preg_replace('#^storage/#', '', $path);
        }

        // already relative
        return ltrim($path, '/');
    }

    protected function toArrayOfPaths($value): array
    {
        if (! $value) {
            return [];
        }

        if (is_string($value)) {
            $rel = $this->normalizeStoragePath($value);

            return $rel ? [$rel] : [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($v) => $this->normalizeStoragePath((string) $v),
                $value
            )));
        }

        return [];
    }

    /**
     * ❗ renamed to avoid clashing with Eloquent's public Model::deleteQuietly()
     */
    protected function deleteFileQuietly(?string $relativePath, string $disk = 'public'): void
    {
        if (! $relativePath) {
            return;
        }
        try {
            Storage::disk($disk)->delete($relativePath);
        } catch (\Throwable $e) {
            // swallow
        }
    }

    /* ================== Public helpers ================== */

    public function getExcel(): array
    {
        return $this->excel;
    }

    /**
     * Phase 1 (2026-10-25): optional convenience scopes for the new
     * `is_active` lifecycle column (kept separate from SoftDeletes'
     * `deleted_at`). Not applied automatically (no global scope), so they
     * are a no-op opt-in — safe to add here even though not every consuming
     * table has an `is_active` column yet; calling these on a table without
     * the column will fail the same way any ->where() on a missing column
     * would, which is the expected, visible failure mode rather than a
     * silently wrong result.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * scopeWithFilters:
     * This is the main entry point used by your controllers.
     * It:
     *  1) applies global search (?search=)
     *  2) applies soft delete mode (?status=0,2)
     *  3) applies legacy $filters mapping
     *  4) applies dot-notation filters (?relation.column=)
     *  5) applies $filterable shortcuts
     *  6) applies dynamic filters/sorting (new engine)
     */
    public function scopeWithFilters($query)
    {
        $request = request();

        /* 1) Global LIKE search (?search=) */
        $query = $query->when($request->filled('search') && ! empty($this->search), function ($q) use ($request) {
            $term = trim((string) $request->search);
            $first = true;
            foreach ($this->search as $column) {
                if ($first) {
                    $q->where($column, 'like', "%{$term}%");
                    $first = false;
                } else {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            }
        });

        /* 2) SoftDeletes status:
            status=0  -> onlyTrashed()
            status=2  -> withTrashed()
            else      -> default (no trashed)
        */
        if ($request->filled('trashed')) {
            $status = (int) $request->input('trashed', 1);
            if ($status === 0) {
                $query = $query->onlyTrashed();
            } elseif ($status === 2) {
                $query = $query->withTrashed();
            }
        }

        /* 3) Legacy mapped filters ($filters on model) */
        foreach ($this->filters as $filterKey => $map) {
            if ($request->filled($filterKey)) {
                $value = $request->input($filterKey);
                $this->applyFilter($query, $filterKey, $map, $value);
            }
        }

        /* 4) Dot-notation filters via request (relation.column=value).
           NOTE: This only runs for raw "relation.column" in the query,
           not the PHP-mutated version with underscores.
           We'll handle underscore / array style in applyDynamicFilters().
        */
        foreach ($request->query() as $key => $value) {
            if (strpos($key, '.') !== false) {
                if (! array_key_exists($key, $this->filters) && $value !== null && $value !== '') {
                    [$relation, $column] = explode('.', $key, 2);
                    $query->whereHas($relation, function ($relQ) use ($column, $value) {
                        $this->applyColumnFilter($relQ, $column, $value);
                    });
                }
            }
        }

        /* 5) Simple $filterable shortcuts */
        foreach ($this->normalizeFilterable() as $reqKey => $cfg) {
            if (! $request->has($reqKey)) {
                continue;
            }

            $column = $cfg['column'];
            $op = $cfg['op'];
            $type = $cfg['type'];

            $val = $request->input($reqKey);

            if ($type === 'bool') {
                $val = $request->boolean($reqKey);
            } elseif ($type === 'int') {
                $val = (int) $val;
            } elseif ($type === 'array') {
                $val = is_array($val)
                    ? $val
                    : (strlen((string) $val) ? explode(',', (string) $val) : []);
            }

            if ($op === 'tree') {
                $modelClass = $this->treeFilterModels[$column] ?? null;

                if (is_array($modelClass)) {
                    $modelClass = $modelClass['model'] ?? null;
                }

                if (! is_string($modelClass) || $modelClass === '') {
                    continue;
                }

                $ids = $this->resolveTreeIds($modelClass, (int) $val);

                if (! empty($ids)) {
                    $query->whereIn($column, $ids);
                }

                continue;
            }

            if ($op === 'like') {
                if ($val !== null && $val !== '') {
                    $query->where($column, 'like', '%'.$val.'%');
                }
            } elseif ($op === 'in') {
                if (is_array($val) && count($val)) {
                    $query->whereIn($column, $val);
                }
            } else {
                if ($val !== null && $val !== '') {
                    $query->where($column, $val);
                }
            }
        }

        /* 6) Dynamic filters/sorting (NEW ENGINE) */
        if ($this->enableDynamicFilters) {
            $this->applyDynamicFilters($query);
            $this->applyDynamicSorting($query);
        }

        return $query;
    }

    /* ===== Legacy helpers (kept) ===== */

    protected function applyFilter($query, string $filterKey, $map, $value): void
    {
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            return;
        }

        if (is_string($map)) {
            $this->applyColumnFilter($query, $map, $value);

            return;
        }

        if (is_array($map)) {
            $isAssoc = array_keys($map) !== range(0, count($map) - 1);

            if ($isAssoc) {
                foreach ($map as $relation => $columns) {
                    if (is_array($columns)) {
                        $query->whereHas($relation, function ($relQ) use ($columns, $value) {
                            foreach ($columns as $col => $op) {
                                $this->applyColumnFilter($relQ, $col, $value, is_string($op) ? $op : null);
                            }
                        });
                    } else {
                        $query->whereHas($relation, function ($relQ) use ($columns, $value) {
                            $this->applyColumnFilter($relQ, $columns, $value);
                        });
                    }
                }
            } else {
                foreach ($map as $col) {
                    $this->applyColumnFilter($query, $col, $value);
                }
            }

            return;
        }

        throw ValidationException::withMessages([
            $filterKey => ['Invalid filter mapping definition.'],
        ]);
    }

    protected function applyColumnFilter($query, string $column, $value, ?string $operator = null): void
    {
        if (is_array($value)) {
            $query->whereIn($column, $value);

            return;
        }

        if ($operator === 'like') {
            $query->where($column, 'like', '%'.$value.'%');

            return;
        }

        $query->where($column, $value);
    }

    protected function normalizeFilterable(): array
    {
        $out = [];

        foreach ($this->filterable as $reqKey => $cfg) {
            if (is_string($cfg)) {
                // shorthand
                if (in_array($cfg, ['bool', 'int', 'string'], true)) {
                    $out[$reqKey] = ['column' => $reqKey, 'op' => 'eq', 'type' => $cfg];
                } elseif ($cfg === 'like') {
                    $out[$reqKey] = ['column' => $reqKey, 'op' => 'like', 'type' => 'string'];
                } elseif ($cfg === 'array' || $cfg === 'in') {
                    $out[$reqKey] = ['column' => $reqKey, 'op' => 'in', 'type' => 'array'];
                } else {
                    $out[$reqKey] = ['column' => $reqKey, 'op' => 'eq', 'type' => 'string'];
                }
            } elseif (is_array($cfg)) {
                // verbose
                $out[$reqKey] = [
                    'column' => $cfg['column'] ?? $reqKey,
                    'op' => $cfg['op'] ?? 'eq',
                    'type' => $cfg['type'] ?? 'string',
                ];
            }
        }

        return $out;
    }

    /* ================== Dynamic parser (NEW, UPDATED) ================== */

    protected function applyDynamicFilters($query): void
    {
        $request = request();
        $params = $request->query();

        // 0) numeric range shortcuts (?price_min=10&price_max=50)
        $this->applyMinMaxShortcuts($query, $params);

        // 1) pass #1: handle scalar params
        //    covers:
        //    - direct columns: ?price=100
        //    - dotted style (if somehow survives): ?details.attribute_id=7
        //    - PHP underscore fallback: ?details_attribute_id=7
        foreach ($params as $rawKey => $value) {
            // skip reserved keys
            if ($rawKey === 'search' || $rawKey === 'status' || $rawKey === 'sort') {
                continue;
            }

            // arrays handled in pass #2
            if (is_array($value)) {
                continue;
            }

            // CASE A: plain column on main table
            // example: ?brand_id=5
            if (strpos($rawKey, '.') === false && strpos($rawKey, '_') === false) {
                [$column, $op] = $this->splitColumnAndOp($rawKey, $value);

                if (! $this->isColumnAllowed($column)) {
                    continue;
                }

                $this->applyColumnOp($query, $column, $op, $value);

                continue;
            }

            // CASE B: original dotted relation.column
            // example: ?details.attribute_id=7
            if (strpos($rawKey, '.') !== false) {
                [$relation, $columnAndOp] = explode('.', $rawKey, 2);
                [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                if (! $this->isColumnAllowed("$relation.$column")) {
                    continue;
                }

                $this->applyRelationFilter($query, $relation, $column, $op, $value);

                continue;
            }

            // CASE C: PHP underscore mutation
            // example sent: ?details.attribute_id=7
            // actually received: details_attribute_id=7
            // interpret first "_" as boundary
            if (strpos($rawKey, '_') !== false) {
                $firstUnderscorePos = strpos($rawKey, '_');
                $relation = substr($rawKey, 0, $firstUnderscorePos);        // "details"
                $columnAndOp = substr($rawKey, $firstUnderscorePos + 1);       // "attribute_id" or "attribute_id_in"

                [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                if (! $this->isColumnAllowed("$relation.$column")) {
                    continue;
                }

                $this->applyRelationFilter($query, $relation, $column, $op, $value);

                continue;
            }
        }

        // 2) pass #2: array-style nested relation filters
        //    example request:
        //    ?details[attribute_id]=7
        //    gives $params = ['details' => ['attribute_id' => 7]]
        foreach ($params as $relation => $subArray) {
            if (! is_array($subArray)) {
                continue;
            }

            foreach ($subArray as $columnAndOp => $value) {
                [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                if (! $this->isColumnAllowed("$relation.$column")) {
                    continue;
                }

                $this->applyRelationFilter($query, $relation, $column, $op, $value);
            }
        }
    }

    protected function applyDynamicSorting($query): void
    {
        $request = request();
        $sortRaw = $request->query('sort');

        if (! $sortRaw) {
            return;
        }

        $columns = array_map('trim', explode(',', (string) $sortRaw));

        foreach ($columns as $col) {
            $dir = 'asc';

            if (str_starts_with($col, '-')) {
                $dir = 'desc';
                $col = ltrim($col, '-');
            }

            if (! $this->isSortable($col)) {
                continue;
            }

            // no relation sorting by default (to avoid auto joins)
            if (strpos($col, '.') !== false) {
                continue;
            }

            $query->orderBy($col, $dir);
        }
    }

    /**
     * splitColumnAndOp:
     * turns "attribute_id_in" => ["attribute_id","in"]
     * turns "price_gte"       => ["price","gte"]
     * if no suffix op is found, falls back to:
     *   - "eq" normally
     *   - "neq"/"nin" when value starts with "!"
     */
    protected function splitColumnAndOp(string $columnAndOp, $value): array
    {
        // look for suffix "_<op>"
        if (preg_match('/^(?<col>.+)_(?<op>[a-z]+)$/i', $columnAndOp, $m)) {
            $col = $m['col'];
            $op = strtolower($m['op']);
            if (! in_array($op, $this->allowedOps, true)) {
                $op = 'eq';
            }

            return [$col, $op];
        }

        // infer negation ("!foo" or "!1,2,3")
        if (is_string($value) && strlen($value) && $value[0] === '!') {
            if (strpos($value, ',') !== false) {
                return [$columnAndOp, 'nin']; // not in
            }

            return [$columnAndOp, 'neq']; // not equal
        }

        return [$columnAndOp, 'eq'];
    }

    protected function isColumnAllowed(string $col): bool
    {
        if (empty($this->dynamicFilterColumns)) {
            return false;
        }

        return in_array($col, $this->dynamicFilterColumns, true);
    }

    protected function isSortable(string $col): bool
    {
        $list = ! empty($this->sortableColumns)
            ? $this->sortableColumns
            : $this->dynamicFilterColumns;

        return in_array($col, $list, true);
    }

    protected function applyRelationFilter($query, string $relation, string $column, string $op, $value): void
    {
        // حاول الحصول على اسم جدول النموذج المرتبط لتأهيل الأعمدة
        try {
            $relationObj = $this->{$relation}(); // returns Relation instance
            $relatedTable = $relationObj->getRelated()->getTable();
        } catch (\Throwable $e) {
            $relatedTable = $relation; // fallback (نادر)
        }

        // إذا كان المستخدم مرّر عمود بعنوان نقطي already (مثال: attribute.id) اتركه
        $qualified = strpos($column, '.') !== false ? $column : "{$relatedTable}.{$column}";

        $query->whereHas($relation, function ($relQ) use ($qualified, $op, $value) {
            $this->applyColumnOp($relQ, $qualified, $op, $value);
        });
    }

    protected function applyColumnOp($query, string $column, string $op, $value): void
    {
        // helper to normalize comma lists
        $csvToArray = function ($v) {
            if (is_array($v)) {
                return $v;
            }
            $v = (string) $v;
            if ($v === '') {
                return [];
            }
            if ($v[0] === '!') {
                $v = substr($v, 1);
            }

            return array_map('trim', explode(',', $v));
        };

        $valueStr = is_string($value) ? $value : '';
        $negated = (is_string($value) && strlen($value) && $value[0] === '!');

        switch ($op) {
            case 'like':
                if ($valueStr === '' && ! is_numeric($value)) {
                    break;
                }

                if ($negated) {
                    $needle = substr($valueStr, 1);
                    $query->where($column, 'NOT LIKE', '%'.$needle.'%');
                } else {
                    $query->where($column, 'LIKE', '%'.$value.'%');
                }
                break;

            case 'nlike':
                if ($valueStr === '' && ! is_numeric($value)) {
                    break;
                }
                $query->where($column, 'NOT LIKE', '%'.$value.'%');
                break;

            case 'in':
                $vals = $csvToArray($value);
                if (! empty($vals)) {
                    $query->whereIn($column, $vals);
                }
                break;

            case 'nin':
                $vals = $csvToArray($value);
                if (! empty($vals)) {
                    $query->whereNotIn($column, $vals);
                }
                break;

            case 'gt':
                $query->where($column, '>', $value);
                break;

            case 'gte':
                $query->where($column, '>=', $value);
                break;

            case 'lt':
                $query->where($column, '<', $value);
                break;

            case 'lte':
                $query->where($column, '<=', $value);
                break;

            case 'between':
                $vals = is_array($value) ? $value : $csvToArray($value);
                if (count($vals) >= 2) {
                    $query->whereBetween($column, [$vals[0], $vals[1]]);
                }
                break;

            case 'bool':
                $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) {
                    $query->where($column, $bool);
                }
                break;

            case 'null':
                $flag = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($flag === true) {
                    $query->whereNull($column);
                }
                if ($flag === false) {
                    $query->whereNotNull($column);
                }
                break;

            case 'tree':
                $treeCfg = $this->treeFilterModels[$column] ?? null;

                if (! $treeCfg || ! isset($treeCfg['model'])) {
                    break;
                }

                $ids = $this->resolveTreeIds(
                    $treeCfg['model'],
                    (int) $value,
                    $treeCfg['key'] ?? 'id',
                    $treeCfg['parent_key'] ?? 'parent_id'
                );

                if (! empty($ids)) {
                    $query->whereIn($column, $ids);
                }

                break;

            case 'neq':
                if ($negated) {
                    $value = substr($valueStr, 1);
                }
                $query->where($column, '!=', $value);
                break;

            case 'eq':
            default:
                if ($negated) {
                    $val = substr($valueStr, 1);
                    if (strpos($val, ',') !== false) {
                        $vals = array_map('trim', explode(',', $val));
                        $query->whereNotIn($column, $vals);
                    } else {
                        $query->where($column, '!=', $val);
                    }
                } else {
                    if ($value !== '' && $value !== null) {
                        $query->where($column, $value);
                    }
                }
                break;
        }
    }

    protected function applyMinMaxShortcuts($query, array $params): void
    {
        foreach ($this->dynamicFilterColumns as $col) {
            // فقط على أعمدة الموديل الأساسي (بدون علاقة)
            if (strpos($col, '.') !== false) {
                continue;
            }

            $minKey = "{$col}_min";
            $maxKey = "{$col}_max";

            $hasMin = array_key_exists($minKey, $params) && $params[$minKey] !== '';
            $hasMax = array_key_exists($maxKey, $params) && $params[$maxKey] !== '';

            if (! $hasMin && ! $hasMax) {
                continue;
            }

            if ($hasMin && $hasMax) {
                $query->whereBetween($col, [$params[$minKey], $params[$maxKey]]);
            } elseif ($hasMin) {
                $query->where($col, '>=', $params[$minKey]);
            } else {
                $query->where($col, '<=', $params[$maxKey]);
            }
        }
    }

    protected function resolveTreeIds(string $modelClass, int $rootId, string $key = 'id', string $parentKey = 'parent_id'): array
    {
        $ids = [$rootId];
        $queue = [$rootId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);

            $children = $modelClass::query()
                ->where($parentKey, $currentId)
                ->pluck($key)
                ->all();

            foreach ($children as $childId) {
                if (! in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }
}
