<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI P0 (2026-10-30). Run log of the default-currency
 * re-denomination job. Table and model only — the job itself is P7.
 */
class CurrencyRedenomination extends BasePlatformModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'from_currency_id' => 'from_currency_id',
        'to_currency_id' => 'to_currency_id',
        'conversion_rate' => 'conversion_rate',
        'status' => 'status',
        'idempotency_key' => 'idempotency_key',
        'requested_by_admin_id' => 'requested_by_admin_id',
        'started_at' => 'started_at',
        'completed_at' => 'completed_at',
        'failed_at' => 'failed_at',
        'error' => 'error',
        'summary' => 'summary',
    ];

    protected $hidden = ['inflight_marker'];

    protected $casts = [
        'conversion_rate' => 'decimal:12',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }
}
