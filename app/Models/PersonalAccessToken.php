<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * WAFFRLI (2026-11-21, capacity audit) — Sanctum writes `last_used_at` on
 * EVERY authenticated request (vendor Guard::updateLastUsedAt), i.e. one
 * extra UPDATE per API call. Nothing in WAFFRLI reads `last_used_at` (token
 * expiry uses created_at/expires_at), so the write is skipped while the
 * stored value is less than LAST_USED_RESOLUTION seconds old: at most one
 * write per token per minute. Any other change to a token saves normally.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public const LAST_USED_RESOLUTION = 60;

    protected static function booted(): void
    {
        static::saving(function (self $token): ?bool {
            if (! $token->exists) {
                return null;
            }
            $dirty = array_keys($token->getDirty());
            $onlyLastUsed = array_diff($dirty, ['last_used_at', 'updated_at']) === [] && in_array('last_used_at', $dirty, true);
            $previous = $token->getOriginal('last_used_at');
            if ($onlyLastUsed && $previous !== null && $token->asDateTime($previous)->gt(now()->subSeconds(self::LAST_USED_RESOLUTION))) {
                $token->syncOriginal();   // keep the in-memory model consistent with the row

                return false;              // cancel this save only
            }

            return null;
        });
    }
}
