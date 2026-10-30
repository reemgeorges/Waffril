<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Verified-purchase product rating; moderation via
 * App\Services\Catalog\ProductRatingService (keeps aggregates exact).
 */
class ProductRating extends BasePlatformModel
{
    protected $fillable = [
        'user_id' => 'user_id',
        'product_id' => 'product_id',
        'order_id' => 'order_id',
        'rating' => 'rating',
        'comment' => 'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_hidden' => 'boolean',
        'hidden_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'hidden_by_admin_id');
    }
}
