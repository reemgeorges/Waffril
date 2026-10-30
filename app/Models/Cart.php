<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). One active cart per (customer, branch). Carts
 * hold no prices.
 */
class Cart extends BasePlatformModel
{
    protected $fillable = ['user_id' => 'user_id', 'branch_id' => 'branch_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
