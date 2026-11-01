<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * WAFFRLI PLUS (2026-10-31). The logical subscription (one per
 * customer). Benefits are realised only through admin-confirmed cycles.
 */
class PlusMembership extends BasePlatformModel
{
    public const POLICY_MONTHLY = 'monthly';

    public const POLICY_ONE_TIME = 'one_time';

    public const POLICY_CUSTOM = 'custom';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'user_id' => 'user_id',
        'plus_plan_id' => 'plus_plan_id',
        'next_plan_id' => 'next_plan_id',
        'renewal_policy' => 'renewal_policy',
        'custom_duration_days' => 'custom_duration_days',
        'free_deliveries_override' => 'free_deliveries_override',
        'override_approved_by_admin_id' => 'override_approved_by_admin_id',
        'status' => 'status',
        'policy_set_by_admin_id' => 'policy_set_by_admin_id',
        'cancelled_at' => 'cancelled_at',
        'ended_at' => 'ended_at',
    ];

    protected $casts = [
        'custom_duration_days' => 'integer',
        'free_deliveries_override' => 'integer',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlusPlan::class, 'plus_plan_id')->withTrashed();
    }

    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(PlusPlan::class, 'next_plan_id')->withTrashed();
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PlusCycle::class)->orderByDesc('cycle_number');
    }

    public function activeCycle(): HasOne
    {
        return $this->hasOne(PlusCycle::class)->where('status', PlusCycle::STATUS_ACTIVE);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PlusStoreDiscount::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PlusRequest::class);
    }
}
