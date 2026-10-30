<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global product-category tree.
 *
 * WAFFRLI (2026-10-31): name/description became translatable JSON (see
 * 2026_10_31_110200_rework_categories_for_product_catalog), image became
 * optional, parent_id/sort_order added, and products relate through the
 * product_category pivot (a product may have several categories).
 */
class Category extends BaseModel
{
    protected $fillable = [
        'name' => 'name',
        'slug' => 'slug',
        'image' => 'image',
        'description' => 'description',
        // Phase 1 (2026-10-25): is_active added by the additive migration
        // database/migrations/2026_10_25_120000_add_lifecycle_columns_to_users_tests_categories_tables.php
        'is_active' => 'is_active',
        'parent_id' => 'parent_id',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name', 'description'];

    protected array $fileAttributes = ['image' => 'single'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_category');
    }
}
