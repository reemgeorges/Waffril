<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). A store owns its catalog (products) and its
 * branches. Exactly one logo. Store owners have no login.
 */
class Store extends BaseModel
{
    protected $fillable = [
        'name' => 'name',
        'slug' => 'slug',
        'logo' => 'logo',
        'description' => 'description',
        'contact_phone' => 'contact_phone',
        'contact_whatsapp' => 'contact_whatsapp',
        'is_active' => 'is_active',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name', 'description'];

    protected array $fileAttributes = ['logo' => 'single'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(StoreCategory::class, 'store_store_category');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function plusStoreDiscounts(): HasMany
    {
        return $this->hasMany(PlusStoreDiscount::class);
    }
}
