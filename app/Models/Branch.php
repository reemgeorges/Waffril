<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). A store branch in one city. Delivery fees and
 * coverage are platform-wide rules, never branch settings.
 */
class Branch extends BaseModel
{
    protected $fillable = [
        'store_id' => 'store_id',
        'city_id' => 'city_id',
        'name' => 'name',
        'address' => 'address',
        'lat' => 'lat',
        'lng' => 'lng',
        'phone' => 'phone',
        'min_order_amount' => 'min_order_amount',
        'is_active' => 'is_active',
        'is_accepting_orders' => 'is_accepting_orders',
    ];

    protected $translatable = ['name', 'address'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'min_order_amount' => 'decimal:4',
        'is_active' => 'boolean',
        'is_accepting_orders' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(BranchOpeningHour::class)->orderBy('day_of_week')->orderBy('opens_at');
    }

    public function hourExceptions(): HasMany
    {
        return $this->hasMany(BranchHourException::class)->orderByDesc('starts_at');
    }

    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'branch_products')
            ->withPivot(['id', 'price', 'is_available'])->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
