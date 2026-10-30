<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAFFRLI (2026-10-31). Weekly slot; day_of_week 0 = Sunday … 6 =
 * Saturday. closes_at earlier than opens_at means the slot ends after
 * midnight.
 */
class BranchOpeningHour extends BasePlatformModel
{
    protected $fillable = [
        'branch_id' => 'branch_id',
        'day_of_week' => 'day_of_week',
        'opens_at' => 'opens_at',
        'closes_at' => 'closes_at',
    ];

    protected $casts = ['day_of_week' => 'integer'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
