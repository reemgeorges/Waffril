<?php

namespace App\Models;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WAFFRLI (2026-10-31). One payment per method per order. Amount columns
 * are changed only by the order/payment services; the database enforces
 * reversed + refunded <= collected <= amount.
 */
class OrderPayment extends BasePlatformModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'order_id' => 'order_id',
        'payment_method_id' => 'payment_method_id',
        'currency_id' => 'currency_id',
        'amount' => 'amount',
        'status' => 'status',
        'provider' => 'provider',
        'provider_reference' => 'provider_reference',
        'meta' => 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'collected_amount' => 'decimal:4',
        'reversed_amount' => 'decimal:4',
        'refunded_amount' => 'decimal:4',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'collected_by_driver_id')->withTrashed();
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DriverCashSettlement::class, 'driver_cash_settlement_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    /** collected − reversed − refunded: the most that can still be returned. */
    public function returnableAmount(): BigDecimal
    {
        return Money::of((string) $this->collected_amount)
            ->minus((string) $this->reversed_amount)
            ->minus((string) $this->refunded_amount);
    }

    public function isWallet(): bool
    {
        return $this->method?->kind === 'wallet';
    }

    public function isCash(): bool
    {
        return $this->method?->kind === 'cash';
    }
}
