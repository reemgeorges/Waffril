<?php

namespace App\Models;

use App\Enums\DeliveryOfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI dispatch B1 (2026-11-10). One offer of an accepted order to one
 * driver, and its outcome. Rows are history: never deleted or reused.
 * State changes go through App\Services\Delivery\DeliveryOfferService.
 */
class DeliveryOffer extends BasePlatformModel
{
    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_AUTO = 'auto';

    public const REJECT_REASONS = ['too_far', 'busy', 'vehicle_issue', 'other'];

    public const CANCEL_ADMIN_OVERRIDE = 'admin_override';

    public const CANCEL_ADMIN_RESTART = 'admin_restart';

    public const CANCEL_ORDER_CANCELLED = 'order_cancelled';

    public const CANCEL_ORDER_UNASSIGNABLE = 'order_unassignable';

    public const CANCEL_DRIVER_UNAVAILABLE = 'driver_unavailable';

    protected $fillable = [
        'order_id' => 'order_id',
        'driver_id' => 'driver_id',
        'round' => 'round',
        'attempt' => 'attempt',
        'status' => 'status',
        'source' => 'source',
        'offered_by_admin_id' => 'offered_by_admin_id',
        'offered_at' => 'offered_at',
        'expires_at' => 'expires_at',
        'responded_at' => 'responded_at',
        'reject_reason' => 'reject_reason',
        'reject_note' => 'reject_note',
        'cancel_reason' => 'cancel_reason',
        'selection_meta' => 'selection_meta',
    ];

    protected $hidden = ['pending_order_marker', 'pending_driver_marker'];

    protected $casts = [
        'status' => DeliveryOfferStatus::class,
        'round' => 'integer',
        'attempt' => 'integer',
        'offered_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'selection_meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'offered_by_admin_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DeliveryOfferStatus::Pending->value);
    }

    /** Pending and past its deadline (server time). */
    public function isOverdue(): bool
    {
        return $this->status === DeliveryOfferStatus::Pending && ! now()->lessThan($this->expires_at);
    }
}
