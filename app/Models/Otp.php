<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Phase 3A (2026-10-25). Not a lifecycle-managed CRUD resource — like
 * DeviceToken (Phase 2A), this intentionally does not extend BaseModel
 * (no soft deletes/file cleanup needed for a short-lived, purpose-bound
 * secret). All reads/writes go through App\Services\Auth\OtpService; this
 * class has no business logic of its own beyond the `verifiable` relation
 * and a couple of state-check helpers.
 */
class Otp extends Model
{
    protected $table = 'otps';

    protected $fillable = [
        'verifiable_type',
        'verifiable_id',
        'purpose',
        'channel',
        'destination_hash',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'ip_hash',
        // Phase 3B (2026-10-25): set by App\Jobs\Otp\DeliverOtpNotificationJob
        // after the issuing transaction has committed — see OtpService::issue().
        'delivered_at',
        'delivery_failed_at',
        'last_delivery_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'delivered_at' => 'datetime',
        'delivery_failed_at' => 'datetime',
    ];

    /**
     * Never serialize these — they're internal verification state, not API
     * response data (nothing currently serializes this model anyway, but a
     * future accidental ::make()/toArray() call should fail safe).
     */
    protected $hidden = [
        'code_hash',
        'destination_hash',
        'ip_hash',
    ];

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->consumed_at && ! $this->invalidated_at && ! $this->isExpired();
    }
}
