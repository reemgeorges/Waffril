<?php

namespace App\Models;

use App\Support\HasDynamicFiltering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * WAFFRLI P0 (2026-10-30) — base for platform reference data that is
 * deactivated, never deleted (settings, currencies, cities, delivery
 * areas, delivery fee rules).
 *
 * BaseModel always mixes in SoftDeletes, which requires a `deleted_at`
 * column the approved schema deliberately does not have for these
 * tables (they are referenced with RESTRICT foreign keys). This base
 * keeps everything else BasicCrudService/FatherCrudController rely on —
 * translatable attributes, the shared ?search/?active/dynamic filters
 * engine (HasDynamicFiltering, same trait BaseAuthModel uses) — and
 * defines withTrashed()/onlyTrashed() as honest no-ops: these models can
 * never be trashed, so "with trashed" is the plain query and "only
 * trashed" is always empty. That keeps BasicCrudService::show() (which
 * calls withTrashed()) and the shared `?status=` filter working.
 */
abstract class BasePlatformModel extends Model
{
    use HasDynamicFiltering, HasFactory, HasTranslations;

    /** Spatie translatable attributes */
    protected $translatable = [];

    /** Global LIKE search columns (used by ?search=) */
    protected $search = [];

    protected bool $enableDynamicFilters = true;

    protected array $dynamicFilterColumns = [];

    protected array $sortableColumns = [];

    protected array $allowedIncludes = [];

    public function getAllowedIncludes(): array
    {
        return $this->allowedIncludes;
    }

    public function scopeWithTrashed(Builder $query): Builder
    {
        return $query;
    }

    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}
