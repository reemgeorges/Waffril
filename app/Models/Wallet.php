<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). Per-currency customer balance. The balance is
 * changed ONLY by App\Services\Wallet\WalletLedger (append-only ledger,
 * row lock); it is never mass assignable and never edited by admins.
 */
class Wallet extends BasePlatformModel
{
    protected $fillable = ['user_id' => 'user_id', 'currency_id' => 'currency_id'];

    protected $casts = ['balance' => 'decimal:4'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderByDesc('id');
    }
}
