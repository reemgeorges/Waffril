<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Order line snapshot (order currency).
 */
class OrderItem extends BasePlatformModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id' => 'order_id',
        'product_id' => 'product_id',
        'branch_product_id' => 'branch_product_id',
        'product_name' => 'product_name',
        'product_image' => 'product_image',
        'base_unit_price' => 'base_unit_price',
        'options_unit_price' => 'options_unit_price',
        'unit_price' => 'unit_price',
        'quantity' => 'quantity',
        'line_subtotal' => 'line_subtotal',
        'offer_id' => 'offer_id',
        'offer_discount_amount' => 'offer_discount_amount',
        'line_total' => 'line_total',
        'notes' => 'notes',
    ];

    protected $casts = [
        'product_name' => 'array',
        'base_unit_price' => 'decimal:4',
        'options_unit_price' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'quantity' => 'integer',
        'line_subtotal' => 'decimal:4',
        'offer_discount_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
