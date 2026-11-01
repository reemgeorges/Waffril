<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Append-only wallet ledger entry.
 */
class WalletTransaction extends BasePlatformModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const TYPE_ORDER_PAYMENT = 'order_payment';

    public const TYPE_ORDER_PAYMENT_REVERSAL = 'order_payment_reversal';

    public const TYPE_ORDER_REFUND = 'order_refund';

    protected $fillable = [
        'wallet_id' => 'wallet_id',
        'type' => 'type',
        'direction' => 'direction',
        'amount' => 'amount',
        'balance_after' => 'balance_after',
        'order_id' => 'order_id',
        'order_payment_id' => 'order_payment_id',
        'order_refund_id' => 'order_refund_id',
        'idempotency_key' => 'idempotency_key',
        'note' => 'note',
    ];

    protected $casts = ['amount' => 'decimal:4', 'balance_after' => 'decimal:4'];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }
}
