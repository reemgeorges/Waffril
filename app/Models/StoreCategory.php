<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * WAFFRLI (2026-10-31). Store category (restaurants, supermarkets, ...).
 */
class StoreCategory extends BaseModel
{
    protected $fillable = [
        'name' => 'name',
        'slug' => 'slug',
        'image' => 'image',
        'sort_order' => 'sort_order',
        'is_active' => 'is_active',
    ];

    protected $translatable = ['name'];

    protected array $fileAttributes = ['image' => 'single'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_store_category');
    }
}
