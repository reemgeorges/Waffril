<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Selected option snapshot of an order line.
 */
class OrderItemOption extends BasePlatformModel
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id' => 'order_item_id',
        'product_option_id' => 'product_option_id',
        'group_name' => 'group_name',
        'option_name' => 'option_name',
        'quantity' => 'quantity',
        'unit_price_delta' => 'unit_price_delta',
        'total_price_delta' => 'total_price_delta',
    ];

    protected $casts = [
        'group_name' => 'array',
        'option_name' => 'array',
        'quantity' => 'integer',
        'unit_price_delta' => 'decimal:4',
        'total_price_delta' => 'decimal:4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
