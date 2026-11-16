<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WAFFRLI (2026-11-16) — one group of identical application errors (see App\Services\Monitoring\ErrorMonitor). */
class ErrorEvent extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    protected $guarded = ['id'];

    protected $casts = [
        'occurrences' => 'integer',
        'line' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'regressed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by_admin_id');
    }
}
