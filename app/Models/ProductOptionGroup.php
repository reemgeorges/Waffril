<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Option group: `size` (exactly one choice),
 * `choice` or `addon`, with min/max selection (sum of quantities).
 */
class ProductOptionGroup extends BaseModel
{
    public const KIND_SIZE = 'size';

    public const KIND_CHOICE = 'choice';

    public const KIND_ADDON = 'addon';

    protected $fillable = [
        'product_id' => 'product_id',
        'name' => 'name',
        'kind' => 'kind',
        'min_select' => 'min_select',
        'max_select' => 'max_select',
        'sort_order' => 'sort_order',
        'is_active' => 'is_active',
    ];

    protected $translatable = ['name'];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class, 'option_group_id')->orderBy('sort_order');
    }
}
