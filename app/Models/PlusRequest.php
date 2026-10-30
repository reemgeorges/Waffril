<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * WAFFRLI PLUS (2026-10-31). Customer (or system-generated renewal)
 * request; resolved only by an admin through App\Services\Plus\PlusService.
 */
class PlusRequest extends BasePlatformModel
{
    public const TYPE_NEW = 'new';

    public const TYPE_RENEW = 'renew';

    public const TYPE_CHANGE = 'change';

    public const TYPE_CANCEL = 'cancel';

    public const TYPE_CUSTOM = 'custom';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'user_id' => 'user_id',
        'plus_membership_id' => 'plus_membership_id',
        'type' => 'type',
        'requested_plan_id' => 'requested_plan_id',
        'customer_note' => 'customer_note',
        'source' => 'source',
        'status' => 'status',
        'admin_note' => 'admin_note',
        'reviewed_by_admin_id' => 'reviewed_by_admin_id',
        'reviewed_at' => 'reviewed_at',
    ];

    protected $hidden = ['pending_key'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(PlusMembership::class, 'plus_membership_id');
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(PlusPlan::class, 'requested_plan_id')->withTrashed();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function cycle(): HasOne
    {
        return $this->hasOne(PlusCycle::class);
    }
}
