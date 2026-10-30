<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Customer request to cancel an order that is
 * already accepted; resolved by an admin.
 */
class OrderCancellationRequest extends BasePlatformModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'order_id' => 'order_id',
        'customer_note' => 'customer_note',
        'status' => 'status',
        'resolved_by_admin_id' => 'resolved_by_admin_id',
        'resolved_at' => 'resolved_at',
        'admin_note' => 'admin_note',
    ];

    protected $hidden = ['pending_marker'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by_admin_id');
    }
}
