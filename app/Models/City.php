<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI P0 (2026-10-30). Delivery city; GPS detection uses its center
 * and radius_km.
 */
class City extends BasePlatformModel
{
    protected $fillable = [
        'name' => 'name',
        'center_lat' => 'center_lat',
        'center_lng' => 'center_lng',
        'radius_km' => 'radius_km',
        'is_active' => 'is_active',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name'];

    protected $casts = [
        'center_lat' => 'decimal:7',
        'center_lng' => 'decimal:7',
        'radius_km' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected array $dynamicFilterColumns = ['is_active'];

    protected array $sortableColumns = ['sort_order', 'created_at'];

    public function deliveryAreas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class);
    }
}
