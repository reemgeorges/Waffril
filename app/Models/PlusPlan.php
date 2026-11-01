<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI PLUS (2026-10-31). Plan with fixed benefits (free deliveries
 * per cycle + store discounts). display_price is informational: payment
 * and agreement happen off-platform.
 */
class PlusPlan extends BaseModel
{
    protected $fillable = [
        'code' => 'code',
        'name' => 'name',
        'description' => 'description',
        'default_duration_days' => 'default_duration_days',
        'free_deliveries_per_cycle' => 'free_deliveries_per_cycle',
        'display_price' => 'display_price',
        'is_active' => 'is_active',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name', 'description'];

    protected $casts = [
        'default_duration_days' => 'integer',
        'free_deliveries_per_cycle' => 'integer',
        'display_price' => 'decimal:4',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function storeDiscounts(): HasMany
    {
        return $this->hasMany(PlusStoreDiscount::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PlusMembership::class);
    }
}
