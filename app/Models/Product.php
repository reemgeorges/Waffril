<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Store-owned product definition shared by the
 * store's branches (prices/availability are per branch). One image.
 *
 * rating_sum/rating_count cover genuine, visible ratings only; the
 * admin placeholder rating (setting `catalog.placeholder_rating`) is
 * returned by displayRating() only when there is no genuine rating.
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
        'rating_count' => 'integer',
        'rating_sum' => 'integer',
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

    public function ratings(): HasMany
    {
        return $this->hasMany(ProductRating::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withPivot('created_at');
    }

    public function averageRating(): ?float
    {
        return $this->rating_count > 0 ? round($this->rating_sum / $this->rating_count, 2) : null;
    }

    /** Customer-facing aggregate: genuine average, else the configured placeholder. */
    public function displayRating(): ?float
    {
        $average = $this->averageRating();
        if ($average !== null) {
            return $average;
        }

        $placeholder = Setting::query()->where('key', 'catalog.placeholder_rating')->value('value');

        return is_numeric($placeholder) ? (float) $placeholder : null;
    }
}
