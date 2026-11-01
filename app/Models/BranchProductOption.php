<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Per-branch price delta (>= 0, default currency)
 * and availability of a product option. No row = not offered there.
 */
class BranchProductOption extends BasePlatformModel
{
    protected $fillable = [
        'branch_product_id' => 'branch_product_id',
        'product_id' => 'product_id',
        'product_option_id' => 'product_option_id',
        'price_delta' => 'price_delta',
        'is_available' => 'is_available',
    ];

    protected $casts = [
        'price_delta' => 'decimal:4',
        'is_available' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if ($row->branch_product_id && ! $row->product_id) {
                $row->product_id = BranchProduct::query()->whereKey($row->branch_product_id)->value('product_id');
            }
        });
    }

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
