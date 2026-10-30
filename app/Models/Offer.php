<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Public store offer (store / branch / product
 * scope). PLUS discounts take priority; offers never combine with PLUS.
 */
class Offer extends BaseModel
{
    protected $fillable = [
        'store_id' => 'store_id',
        'branch_id' => 'branch_id',
        'product_id' => 'product_id',
        'name' => 'name',
        'discount_type' => 'discount_type',
        'discount_value' => 'discount_value',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'is_active' => 'is_active',
        'created_by_admin_id' => 'created_by_admin_id',
    ];

    protected $translatable = ['name'];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
