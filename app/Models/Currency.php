<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * WAFFRLI P0 (2026-10-30). exchange_rate = units of this currency per 1
 * unit of the default currency (the default is always exactly 1).
 *
 * `is_default` and `exchange_rate` of the default are deliberately NOT
 * mass assignable: the first default is set only through
 * CurrencyService::initializeDefault(), and switching an existing default
 * is reserved for the P7 re-denomination job.
 */
class Currency extends BasePlatformModel
{
    protected $fillable = [
        'code' => 'code',
        'name' => 'name',
        'symbol' => 'symbol',
        'decimals' => 'decimals',
        'exchange_rate' => 'exchange_rate',
        'is_active' => 'is_active',
        'rate_updated_at' => 'rate_updated_at',
        'rate_updated_by_admin_id' => 'rate_updated_by_admin_id',
    ];

    protected $translatable = ['name'];

    protected $hidden = ['default_marker'];

    protected $casts = [
        'decimals' => 'integer',
        'exchange_rate' => 'decimal:12',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'rate_updated_at' => 'datetime',
    ];

    protected $search = ['code'];

    protected array $dynamicFilterColumns = ['code', 'is_active', 'is_default'];

    protected array $sortableColumns = ['code', 'created_at'];

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
