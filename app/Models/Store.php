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
        'plus_discount_percent' => 'plus_discount_percent',
    ];

    protected $translatable = ['name', 'description'];

    protected array $fileAttributes = ['logo' => 'single'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer', 'plus_discount_percent' => 'decimal:2'];

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

    /**
     * The store's general PLUS discount for display ("5", "2.5"), or null
     * when the store has none. Granted only to active PLUS members
     * (OrderPricingService); the catalog shows it as a PLUS benefit.
     */
    public function plusDiscountPercentText(): ?string
    {
        $text = (string) ($this->plus_discount_percent ?? '0');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || (float) $text <= 0 ? null : $text;
    }
}
