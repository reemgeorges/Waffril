<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Immutable, one-per-order cancellation record with
 * its financial consequences.
 */
class OrderCancellation extends BasePlatformModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const REASONS = ['customer_request', 'product_unavailable', 'branch_unavailable', 'customer_unreachable', 'customer_refused', 'other'];

    public const DRIVER_HANDLING = ['not_applicable', 'returned_to_branch', 'disposed', 'retained_by_customer'];

    protected $fillable = [
        'order_id' => 'order_id',
        'from_status' => 'from_status',
        'cancelled_by' => 'cancelled_by',
        'admin_id' => 'admin_id',
        'cancellation_request_id' => 'cancellation_request_id',
        'reason_code' => 'reason_code',
        'note' => 'note',
        'driver_id' => 'driver_id',
        'driver_handling' => 'driver_handling',
        'cash_collected_amount' => 'cash_collected_amount',
        'wallet_reversed_amount' => 'wallet_reversed_amount',
        'free_delivery_restored' => 'free_delivery_restored',
        'idempotency_key' => 'idempotency_key',
    ];

    protected $casts = [
        'cash_collected_amount' => 'decimal:4',
        'wallet_reversed_amount' => 'decimal:4',
        'free_delivery_restored' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }
}
