<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). `product_id` is copied from the group so the
 * database can enforce branch-option integrity with composite FKs; it is
 * always filled from the group on create.
 */
class ProductOption extends BaseModel
{
    protected $fillable = [
        'option_group_id' => 'option_group_id',
        'product_id' => 'product_id',
        'name' => 'name',
        'max_quantity' => 'max_quantity',
        'sort_order' => 'sort_order',
        'is_active' => 'is_active',
    ];

    protected $translatable = ['name'];

    protected $casts = [
        'max_quantity' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $option) {
            if ($option->option_group_id && ! $option->product_id) {
                $option->product_id = ProductOptionGroup::withTrashed()->whereKey($option->option_group_id)->value('product_id');
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branchOptions(): HasMany
    {
        return $this->hasMany(BranchProductOption::class);
    }
}
