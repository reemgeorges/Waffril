<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Phase 2A (2026-10-25). Lightweight, polymorphic device/token metadata
 * record — not a lifecycle-managed CRUD resource, so it intentionally does
 * not extend BaseModel (no soft deletes, no file-attribute cleanup, no
 * dynamic filtering overhead needed here).
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'token',
        'platform',
        'device_name',
        'device_id',
        'is_active',
        'last_seen_at',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
