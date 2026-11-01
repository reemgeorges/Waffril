<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * WAFFRLI (2026-10-31). Append-only audit entry of an admin operation.
 */
class AdminAuditLog extends BasePlatformModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id' => 'admin_id',
        'action' => 'action',
        'auditable_type' => 'auditable_type',
        'auditable_id' => 'auditable_id',
        'properties' => 'properties',
        'ip_address' => 'ip_address',
    ];

    protected $casts = ['properties' => 'array'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
