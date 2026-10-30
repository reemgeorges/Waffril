<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * WAFFRLI (2026-10-31). An order belongs to one branch, is never edited
 * after submission, and keeps snapshots of everything the customer saw.
 * Status changes go exclusively through App\Services\Order\OrderStateMachine.
 */
class Order extends BasePlatformModel
{
    public const TYPE_IMMEDIATE = 'immediate';

    public const TYPE_SCHEDULED = 'scheduled';

    protected $fillable = [
        'order_number' => 'order_number',
        'user_id' => 'user_id',
        'client_request_id' => 'client_request_id',
        'branch_id' => 'branch_id',
        'city_id' => 'city_id',
        'delivery_area_id' => 'delivery_area_id',
        'delivery_fee_rule_id' => 'delivery_fee_rule_id',
        'user_address_id' => 'user_address_id',
        'delivery_address' => 'delivery_address',
        'delivery_lat' => 'delivery_lat',
        'delivery_lng' => 'delivery_lng',
        'delivery_distance_km' => 'delivery_distance_km',
        'distance_method' => 'distance_method',
        'type' => 'type',
        'scheduled_for' => 'scheduled_for',
        'status' => 'status',
        'base_currency_id' => 'base_currency_id',
        'currency_id' => 'currency_id',
        'exchange_rate' => 'exchange_rate',
        'subtotal' => 'subtotal',
        'offer_discount_total' => 'offer_discount_total',
        'plus_discount_amount' => 'plus_discount_amount',
        'delivery_fee_original' => 'delivery_fee_original',
        'delivery_fee' => 'delivery_fee',
        'total' => 'total',
        'plus_cycle_id' => 'plus_cycle_id',
        'plus_cycle_discount_id' => 'plus_cycle_discount_id',
        'free_delivery_applied' => 'free_delivery_applied',
        'customer_notes' => 'customer_notes',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'delivery_address' => 'array',
        'delivery_lat' => 'decimal:7',
        'delivery_lng' => 'decimal:7',
        'delivery_distance_km' => 'decimal:3',
        'scheduled_for' => 'datetime',
        'urgent_at' => 'datetime',
        'exchange_rate' => 'decimal:12',
        'subtotal' => 'decimal:4',
        'offer_discount_total' => 'decimal:4',
        'plus_discount_amount' => 'decimal:4',
        'delivery_fee_original' => 'decimal:4',
        'delivery_fee' => 'decimal:4',
        'total' => 'decimal:4',
        'free_delivery_applied' => 'boolean',
        'accepted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class);
    }

    public function deliveryFeeRule(): BelongsTo
    {
        return $this->belongsTo(DeliveryFeeRule::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'user_address_id')->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function plusCycle(): BelongsTo
    {
        return $this->belongsTo(PlusCycle::class);
    }

    public function plusCycleDiscount(): BelongsTo
    {
        return $this->belongsTo(PlusCycleDiscount::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'accepted_by_admin_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by_admin_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(OrderCancellationRequest::class)->orderByDesc('id');
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(OrderCancellation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function isScheduled(): bool
    {
        return $this->type === self::TYPE_SCHEDULED;
    }
}
