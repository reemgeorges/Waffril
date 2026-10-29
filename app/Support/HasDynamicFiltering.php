<?php

namespace App\Support;

trait HasDynamicFiltering
{
    /**
     * Phase 11A (2026-10-26): real bug fix. This is the ONLY LIVE
     * scopeWithFilters() for App\Models\BaseAuthModel (and therefore
     * Admin/User) — its own copy of this method is commented out in
     * BaseAuthModel.php, so this trait's version is what actually runs.
     * It previously hardcoded `?active=` to write to a `status` column,
     * but Admin/User (the only two models currently using this trait)
     * both use `is_active` — confirmed by their own migrations and by
     * BaseAuthModel::scopeActive()/scopeInactive() a few lines above this
     * trait's own injection point, which already use `is_active`. A
     * `?active=0|1` request against /api/v1/admin/users/all (or any
     * other admin/user-guarded list endpoint) would have targeted a
     * column that doesn't exist on either table.
     *
     * Configurable (not re-hardcoded the other way) so a genuinely
     * legacy model that really does use a `status` column can restore
     * the old behavior by overriding this property — never silently
     * breaking a model this trait wasn't written against.
     */
    protected string $activeColumn = 'is_active';

    public function scopeWithFilters($query)
    {
        $request = request();

        // Global search
        $query = $query->when($request->filled('search') && ! empty($this->search), function ($q) use ($request) {
            $term = trim((string) $request->search);

            $q->where(function ($sub) use ($term) {
                foreach ($this->search as $i => $column) {
                    if ($i === 0) {
                        $sub->where($column, 'like', "%{$term}%");
                    } else {
                        $sub->orWhere($column, 'like', "%{$term}%");
                    }
                }
            });
        });

        // Soft delete status
        if ($request->filled('status')) {
            $status = (int) $request->input('status');
            if ($status === 0) {
                $query->onlyTrashed();
            } elseif ($status === 2) {
                $query->withTrashed();
            }
        }

        // Optional active flag — supports both ?active=0 and ?active=1
        // against whichever column this model actually uses (see
        // $activeColumn's own doc comment above).
        if ($request->filled('active')) {
            $active = (int) $request->input('active');
            $query->where($this->activeColumn ?? 'is_active', $active);
        }

        if ($this->enableDynamicFilters ?? false) {
            $this->applyDynamicFilters($query);
            $this->applyDynamicSorting($query);
        }

        return $query;
    }

    protected function applyDynamicFilters($query): void
    {
        $request = request();
        $params = $request->query();

        $this->applyMinMaxShortcuts($query, $params);

        foreach ($params as $rawKey => $value) {
            if (in_array($rawKey, ['search', 'status', 'sort', 'active'], true)) {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            // 1) Direct column exact match first
            if ($this->isDirectAllowedColumn($rawKey)) {
                [$column, $op] = $this->splitColumnAndOp($rawKey, $value);
                $this->applyColumnOp($query, $column, $op, $value);

                continue;
            }

            // 2) Dotted relation.column
            if (strpos($rawKey, '.') !== false) {
                [$relation, $columnAndOp] = explode('.', $rawKey, 2);
                [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                if ($this->isColumnAllowed("{$relation}.{$column}")) {
                    $this->applyRelationFilter($query, $relation, $column, $op, $value);
                }

                continue;
            }

            // 3) PHP-converted dotted key: relation_column
            foreach ($this->dynamicFilterColumns as $allowed) {
                if (strpos($allowed, '.') === false) {
                    continue;
                }

                [$relation, $allowedColumn] = explode('.', $allowed, 2);
                $prefix = $relation.'_';

                if (str_starts_with($rawKey, $prefix)) {
                    $columnAndOp = substr($rawKey, strlen($prefix));
                    [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                    if ($this->isColumnAllowed("{$relation}.{$column}")) {
                        $this->applyRelationFilter($query, $relation, $column, $op, $value);
                    }

                    break;
                }
            }
        }

        // Array-style nested filters: ?relation[column]=value
        foreach ($params as $relation => $subArray) {
            if (! is_array($subArray)) {
                continue;
            }

            foreach ($subArray as $columnAndOp => $value) {
                [$column, $op] = $this->splitColumnAndOp($columnAndOp, $value);

                if ($this->isColumnAllowed("{$relation}.{$column}")) {
                    $this->applyRelationFilter($query, $relation, $column, $op, $value);
                }
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

            if (strpos($col, '.') !== false) {
                continue;
            }

            $query->orderBy($col, $dir);
        }
    }

    protected function splitColumnAndOp(string $key, $value): array
    {
        $parts = explode('_', $key);

        if (count($parts) > 1) {
            $suffix = end($parts);

            if (in_array(strtolower($suffix), $this->allowedOps, true)) {
                array_pop($parts);

                return [implode('_', $parts), strtolower($suffix)];
            }
        }

        if (is_string($value) && strlen($value) && $value[0] === '!') {
            if (strpos($value, ',') !== false) {
                return [$key, 'nin'];
            }

            return [$key, 'neq'];
        }

        return [$key, 'eq'];
    }

    protected function isDirectAllowedColumn(string $col): bool
    {
        if (empty($this->dynamicFilterColumns)) {
            return false;
        }

        return in_array($col, $this->dynamicFilterColumns, true);
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
        if (! method_exists($this, $relation)) {
            return;
        }

        $relationObj = $this->{$relation}();
        $relatedTable = $relationObj->getRelated()->getTable();
        $qualified = strpos($column, '.') !== false ? $column : "{$relatedTable}.{$column}";

        $query->whereHas($relation, function ($relQ) use ($qualified, $op, $value) {
            $this->applyColumnOp($relQ, $qualified, $op, $value);
        });
    }

    protected function applyColumnOp($query, string $column, string $op, $value): void
    {
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
        $negated = is_string($value) && strlen($value) && $value[0] === '!';

        switch ($op) {
            case 'like':
                if ($valueStr === '' && ! is_numeric($value)) {
                    break;
                }
                $needle = $negated ? substr($valueStr, 1) : $value;
                $query->where($column, $negated ? 'NOT LIKE' : 'LIKE', '%'.$needle.'%');
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
                } elseif ($flag === false) {
                    $query->whereNotNull($column);
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
        foreach (($this->dynamicFilterColumns ?? []) as $col) {
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
}
