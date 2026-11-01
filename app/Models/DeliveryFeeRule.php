<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI P0 (2026-10-30). Platform-wide delivery fee band for an area,
 * a city, or globally. Amounts are in the default currency.
 */
class DeliveryFeeRule extends BasePlatformModel
{
    public const SCOPE_AREA = 'area';

    public const SCOPE_CITY = 'city';

    public const SCOPE_GLOBAL = 'global';

    protected $fillable = [
        'city_id' => 'city_id',
        'delivery_area_id' => 'delivery_area_id',
        'distance_from_km' => 'distance_from_km',
        'distance_to_km' => 'distance_to_km',
        'base_fee' => 'base_fee',
        'per_km_fee' => 'per_km_fee',
        'is_active' => 'is_active',
        'updated_by_admin_id' => 'updated_by_admin_id',
    ];

    protected $casts = [
        'distance_from_km' => 'decimal:2',
        'distance_to_km' => 'decimal:2',
        'base_fee' => 'decimal:4',
        'per_km_fee' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected array $dynamicFilterColumns = ['city_id', 'delivery_area_id', 'is_active', 'scope_key'];

    protected array $sortableColumns = ['distance_from_km', 'created_at'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class);
    }

    /**
     * Same derivation as the generated `scope_key` column, available
     * before the row is saved (used for overlap checks).
     */
    public static function scopeKeyFor(?int $cityId, ?int $areaId): string
    {
        if ($areaId !== null) {
            return 'a:'.$areaId;
        }

        return $cityId !== null ? 'c:'.$cityId : 'g';
    }

    public function scopeType(): string
    {
        if ($this->delivery_area_id !== null) {
            return self::SCOPE_AREA;
        }

        return $this->city_id !== null ? self::SCOPE_CITY : self::SCOPE_GLOBAL;
    }
}
