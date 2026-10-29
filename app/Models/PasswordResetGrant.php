<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Phase 3B (2026-10-25). See the migration's doc comment for why this
 * exists. Plain Model, same reasoning as Otp/DeviceToken — not a
 * lifecycle-managed CRUD resource.
 */
class PasswordResetGrant extends Model
{
    protected $table = 'password_reset_grants';

    protected $fillable = [
        'verifiable_type',
        'verifiable_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
