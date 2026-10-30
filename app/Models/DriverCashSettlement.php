<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Daily driver → platform cash reconciliation per
 * currency. Settled records are immutable (enforced by the service).
 */
class DriverCashSettlement extends BasePlatformModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'driver_id' => 'driver_id',
        'currency_id' => 'currency_id',
        'business_date' => 'business_date',
        'expected_amount' => 'expected_amount',
        'received_amount' => 'received_amount',
        'status' => 'status',
        'settled_by_admin_id' => 'settled_by_admin_id',
        'settled_at' => 'settled_at',
        'note' => 'note',
    ];

    protected $casts = [
        'business_date' => 'date',
        'expected_amount' => 'decimal:4',
        'received_amount' => 'decimal:4',
        'settled_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'settled_by_admin_id');
    }
}
