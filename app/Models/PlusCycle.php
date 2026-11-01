<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * WAFFRLI PLUS (2026-10-31). One admin-confirmed cycle with its own
 * benefit snapshot. Only `status` and `free_deliveries_used` (atomic
 * counter) may change after creation; the snapshot itself is immutable.
 */
class PlusCycle extends BasePlatformModel
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected const MUTABLE = ['status', 'free_deliveries_used', 'updated_at'];

    protected $fillable = [
        'plus_membership_id' => 'plus_membership_id',
        'user_id' => 'user_id',
        'plus_plan_id' => 'plus_plan_id',
        'plus_request_id' => 'plus_request_id',
        'cycle_number' => 'cycle_number',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'status' => 'status',
        'plan_name' => 'plan_name',
        'renewal_policy' => 'renewal_policy',
        'snapshot_currency_id' => 'snapshot_currency_id',
        'free_deliveries_total' => 'free_deliveries_total',
        'free_deliveries_used' => 'free_deliveries_used',
        'confirmed_by_admin_id' => 'confirmed_by_admin_id',
        'confirmed_at' => 'confirmed_at',
    ];

    protected $hidden = ['active_marker'];

    protected $casts = [
        'plan_name' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cycle_number' => 'integer',
        'free_deliveries_total' => 'integer',
        'free_deliveries_used' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $cycle) {
            $changed = array_diff(array_keys($cycle->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new LogicException('PLUS cycle snapshots are immutable: '.implode(', ', $changed));
            }
        });

        static::deleting(fn () => throw new LogicException('PLUS cycles cannot be deleted.'));
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(PlusMembership::class, 'plus_membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlusPlan::class, 'plus_plan_id')->withTrashed();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlusRequest::class, 'plus_request_id');
    }

    public function snapshotCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'snapshot_currency_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmed_by_admin_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(PlusCycleDiscount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function remainingFreeDeliveries(): int
    {
        return max(0, $this->free_deliveries_total - $this->free_deliveries_used);
    }
}
