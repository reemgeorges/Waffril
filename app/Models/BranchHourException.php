<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). `closed` window = holiday / temporary closure
 * (overrides weekly hours); `open` window = special opening hours.
 */
class BranchHourException extends BasePlatformModel
{
    public const KIND_CLOSED = 'closed';

    public const KIND_OPEN = 'open';

    protected $fillable = [
        'branch_id' => 'branch_id',
        'kind' => 'kind',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'reason' => 'reason',
        'created_by_admin_id' => 'created_by_admin_id',
    ];

    protected $translatable = ['reason'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
