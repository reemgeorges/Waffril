<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * WAFFRLI PLUS (2026-10-31). snapshot_* columns are the immutable,
 * admin-approved values; op_* columns are the operational values pricing
 * reads, changed ONLY by the default-currency re-denomination job (P7),
 * which stamps op_redenomination_id.
 */
class PlusCycleDiscount extends BasePlatformModel
{
    public const UPDATED_AT = null;

    protected const MUTABLE = ['op_currency_id', 'op_discount_value', 'op_max_discount_amount', 'op_min_order_amount', 'op_redenomination_id'];

    protected $fillable = [
        'plus_cycle_id' => 'plus_cycle_id',
        'store_id' => 'store_id',
        'source' => 'source',
        'discount_type' => 'discount_type',
        'snapshot_currency_id' => 'snapshot_currency_id',
        'snapshot_discount_value' => 'snapshot_discount_value',
        'snapshot_max_discount_amount' => 'snapshot_max_discount_amount',
        'snapshot_min_order_amount' => 'snapshot_min_order_amount',
        'max_uses_per_cycle' => 'max_uses_per_cycle',
        'op_currency_id' => 'op_currency_id',
        'op_discount_value' => 'op_discount_value',
        'op_max_discount_amount' => 'op_max_discount_amount',
        'op_min_order_amount' => 'op_min_order_amount',
        'op_redenomination_id' => 'op_redenomination_id',
    ];

    protected $casts = [
        'snapshot_discount_value' => 'decimal:4',
        'snapshot_max_discount_amount' => 'decimal:4',
        'snapshot_min_order_amount' => 'decimal:4',
        'op_discount_value' => 'decimal:4',
        'op_max_discount_amount' => 'decimal:4',
        'op_min_order_amount' => 'decimal:4',
        'max_uses_per_cycle' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $discount) {
            $changed = array_diff(array_keys($discount->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new LogicException('PLUS cycle discount snapshots are immutable: '.implode(', ', $changed));
            }
        });

        static::deleting(fn () => throw new LogicException('PLUS cycle discounts cannot be deleted.'));
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PlusCycle::class, 'plus_cycle_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }
}
