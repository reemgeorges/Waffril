<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Store-owned product definition shared by the
 * store's branches (prices/availability are per branch). One image.
 *
 * Product ratings have been replaced by independent order/driver ratings.
 */
class Product extends BaseModel
{
    protected $fillable = [
        'store_id' => 'store_id',
        'name' => 'name',
        'description' => 'description',
        'image' => 'image',
        'is_active' => 'is_active',
    ];

    protected $translatable = ['name', 'description'];

    protected array $fileAttributes = ['image' => 'single'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category');
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class)->orderBy('sort_order');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** Per-branch option prices of this product (branch_product_options.product_id). */
    public function branchOptionPrices(): HasMany
    {
        return $this->hasMany(BranchProductOption::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withPivot('created_at');
    }
}
