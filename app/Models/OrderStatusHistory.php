<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Append-only order status trail.
 */
class OrderStatusHistory extends BasePlatformModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id' => 'order_id',
        'from_status' => 'from_status',
        'to_status' => 'to_status',
        'actor_type' => 'actor_type',
        'actor_id' => 'actor_id',
        'driver_id' => 'driver_id',
        'note' => 'note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }
}
