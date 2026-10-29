<?php

namespace App\Services\Auth;

use App\Models\PasswordResetGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 3B (2026-10-25). Issues and consumes single-use, short-lived,
 * account-bound password reset grants — see the password_reset_grants
 * migration's doc comment for why this exists instead of either "no check
 * at all" or a broad Sanctum token.
 *
 * Same atomicity pattern as App\Services\Auth\OtpService::verifyForModel():
 * consumption is a locked-transaction, conditional UPDATE whose
 * affected-row count is checked.
 */
class PasswordResetGrantService
{
    /**
     * Issue a new grant for $subject, invalidating any still-live grant
     * for that same subject first. Returns the RAW token (never persisted
     * — only its hash is stored) for the caller to hand back to the client.
     */
    public function issue(Model $subject): string
    {
        $token = Str::random(64);

        DB::transaction(function () use ($subject, $token) {
            PasswordResetGrant::where('verifiable_type', get_class($subject))
                ->where('verifiable_id', $subject->getKey())
                ->whereNull('consumed_at')
                ->delete();

            PasswordResetGrant::create([
                'verifiable_type' => get_class($subject),
                'verifiable_id' => $subject->getKey(),
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addMinutes(max(1, (int) config('otp.reset_grant_ttl_minutes', 10))),
            ]);
        });

        return $token;
    }

    /**
     * Atomically consume a grant for $subject if (and only if) $token
     * matches, is unexpired, and has not already been consumed. A grant
     * issued for one account can never be consumed against another —
     * every lookup is scoped by (verifiable_type, verifiable_id).
     */
    public function consume(Model $subject, string $token): bool
    {
        return DB::transaction(function () use ($subject, $token) {
            $grants = PasswordResetGrant::where('verifiable_type', get_class($subject))
                ->where('verifiable_id', $subject->getKey())
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->get();

            foreach ($grants as $grant) {
                if (! Hash::check($token, $grant->token_hash)) {
                    continue;
                }

                $affected = PasswordResetGrant::where('id', $grant->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                return $affected === 1;
            }

            return false;
        });
    }
}
