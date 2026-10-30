<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Per-branch price (default currency) and
 * availability of a product. `store_id` is filled from the branch and
 * the composite FKs reject any product from another store.
 */
class BranchProduct extends BasePlatformModel
{
    protected $fillable = [
        'branch_id' => 'branch_id',
        'product_id' => 'product_id',
        'store_id' => 'store_id',
        'price' => 'price',
        'is_available' => 'is_available',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'is_available' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if ($row->branch_id && ! $row->store_id) {
                $row->store_id = Branch::withTrashed()->whereKey($row->branch_id)->value('store_id');
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(BranchProductOption::class);
    }
}
