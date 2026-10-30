<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * WAFFRLI (2026-10-31). Immutable refund record; always credited to the
 * customer's wallet in the order currency.
 */
class OrderRefund extends BasePlatformModel
{
    use AppendOnly;

    protected $fillable = [
        'order_id' => 'order_id',
        'order_payment_id' => 'order_payment_id',
        'order_cancellation_id' => 'order_cancellation_id',
        'currency_id' => 'currency_id',
        'amount' => 'amount',
        'reason_code' => 'reason_code',
        'note' => 'note',
        'idempotency_key' => 'idempotency_key',
        'created_by_admin_id' => 'created_by_admin_id',
    ];

    protected $casts = ['amount' => 'decimal:4'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class);
    }
}
