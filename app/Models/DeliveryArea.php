<?php

namespace App\Models;

use App\Support\Geo\Polygon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI P0 (2026-10-30). Platform-wide delivery area: a GeoJSON
 * Polygon inside one city. min/max columns are the derived bounding box
 * (written by DeliveryAreaService, never accepted from clients).
 */
class DeliveryArea extends BasePlatformModel
{
    protected $fillable = [
        'city_id' => 'city_id',
        'name' => 'name',
        'boundary' => 'boundary',
        'min_lat' => 'min_lat',
        'max_lat' => 'max_lat',
        'min_lng' => 'min_lng',
        'max_lng' => 'max_lng',
        'is_active' => 'is_active',
        'sort_order' => 'sort_order',
    ];

    protected $translatable = ['name'];

    protected $casts = [
        'boundary' => 'array',
        'min_lat' => 'decimal:7',
        'max_lat' => 'decimal:7',
        'min_lng' => 'decimal:7',
        'max_lng' => 'decimal:7',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected array $dynamicFilterColumns = ['city_id', 'is_active'];

    protected array $sortableColumns = ['sort_order', 'created_at'];

    /**
     * 2026-10-31: the bounding box is (re)derived from the boundary on
     * every save, whichever code path writes the area (P0 API service or
     * the admin panel).
     */
    protected static function booted(): void
    {
        static::saving(function (self $area) {
            if (is_array($area->boundary) && Polygon::validationError($area->boundary) === null) {
                $area->fill(Polygon::boundingBox($area->boundary));
            }
        });
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
