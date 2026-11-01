<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI PLUS (2026-10-31). Owned by a plan (definition) OR by a
 * membership (admin-approved per-member exception: override or
 * exclusion). Changes apply from the NEXT cycle snapshot.
 */
class PlusStoreDiscount extends BasePlatformModel
{
    protected $fillable = [
        'plus_plan_id' => 'plus_plan_id',
        'plus_membership_id' => 'plus_membership_id',
        'store_id' => 'store_id',
        'is_excluded' => 'is_excluded',
        'discount_type' => 'discount_type',
        'discount_value' => 'discount_value',
        'max_discount_amount' => 'max_discount_amount',
        'min_order_amount' => 'min_order_amount',
        'max_uses_per_cycle' => 'max_uses_per_cycle',
        'approved_by_admin_id' => 'approved_by_admin_id',
        'note' => 'note',
        'is_active' => 'is_active',
    ];

    protected $casts = [
        'is_excluded' => 'boolean',
        'discount_value' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'min_order_amount' => 'decimal:4',
        'max_uses_per_cycle' => 'integer',
        'is_active' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlusPlan::class, 'plus_plan_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(PlusMembership::class, 'plus_membership_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }
}
