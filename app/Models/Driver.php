<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Platform driver — separate auth domain (future
 * `driver` Sanctum guard); accounts are created by admins.
 */
class Driver extends BaseAuthModel
{
    public const AVAILABILITY_OFFLINE = 'offline';

    public const AVAILABILITY_AVAILABLE = 'available';

    protected $fillable = [
        'name' => 'name',
        'phone' => 'phone',
        'password' => 'password',
        'city_id' => 'city_id',
        'vehicle_type' => 'vehicle_type',
        'vehicle_plate' => 'vehicle_plate',
        'is_active' => 'is_active',
        'availability' => 'availability',
        'language' => 'language',
    ];

    protected $hidden = ['password'];

    protected $search = ['name', 'phone'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_lat' => 'decimal:7',
            'last_lng' => 'decimal:7',
            'last_location_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function activeOrders(): HasMany
    {
        return $this->hasMany(Order::class)->whereIn('status', [
            OrderStatus::Assigned->value,
            OrderStatus::PickedUp->value,
        ]);
    }

    public function collectedPayments(): HasMany
    {
        return $this->hasMany(OrderPayment::class, 'collected_by_driver_id');
    }

    public function cashSettlements(): HasMany
    {
        return $this->hasMany(DriverCashSettlement::class);
    }

    /** WAFFRLI (2026-11-03): live notifications on the private Reverb channel driver.{id}. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'driver.'.$this->getKey();
    }
}
