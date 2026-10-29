<?php

namespace App\Services\Auth;

use App\Contracts\Auth\OtpChannelProvider;
use App\Contracts\Auth\OtpCodeGenerator;
use App\Exceptions\Otp\OtpException;
use App\Jobs\Otp\DeliverOtpNotificationJob;
use App\Models\Otp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 3A (2026-10-25) — Secure Dynamic OTP Core.
 *
 * Reusable across any Eloquent model (Admin, User, or anything
 * `make:auth-module` generates) — nothing here is hardcoded to a specific
 * account type. Implements App\Contracts\Auth\OtpCodeGenerator (declared,
 * unimplemented, in Phase 2B).
 *
 * Security properties, all enforced here (not left to callers):
 *  - Codes are generated with random_int() (CSPRNG), never mt_rand()/uniqid().
 *  - Only a hash of the code is ever persisted (Hash::make/Hash::check,
 *    the same mechanism this app already uses for passwords) — never
 *    plaintext.
 *  - Single-use: consumption is an atomic, conditional UPDATE
 *    (->whereNull('consumed_at')->update(...)) whose affected-row count is
 *    checked, inside a transaction with a row lock — a second verify() for
 *    an already-consumed code always fails, even if raced against the
 *    first.
 *  - Per-code attempt ceiling (config('otp.max_attempts')), independent of
 *    a separate, broader verify-request rate limit (config('otp.max_verifies')
 *    per config('otp.verify_window_minutes')) that caps how many verify
 *    *requests* an actor/IP may make regardless of how many codes they
 *    cycle through.
 *  - Resend cooldown + send-rate-limit, checked by (subject, purpose), by
 *    hashed destination, and by IP when available — see issue().
 *  - Purposes are fully isolated from each other and from other subjects:
 *    every query is scoped by (verifiable_type, verifiable_id, purpose).
 */
class OtpService implements OtpCodeGenerator
{
    public function generate(string $purpose): string
    {
        $length = max(4, (int) config('otp.code_length', 6));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Issue a new OTP for $subject + $purpose, deliver it over $channel,
     * and invalidate any previously-issued, still-live code for the same
     * (subject, purpose). Throws OtpException (429) on cooldown or rate
     * limit; propagates whatever the channel provider throws (e.g. an
     * unconfigured SMS/WhatsApp channel) unchanged.
     */
    public function issue(Model $subject, string $purpose, string $channel, ?string $destination = null, ?string $ip = null): void
    {
        // Validate the channel BEFORE any state changes, so an unknown
        // channel name never invalidates a prior valid code or consumes
        // rate-limit budget. (Whether the channel is actually *usable* —
        // e.g. SmsOtpChannel always throwing — is discovered later, by the
        // queued delivery job, not here; this only checks the name maps to
        // a real, instantiable OtpChannelProvider class.)
        $this->resolveChannel($channel);

        $subjectType = get_class($subject);
        $subjectId = $subject->getKey();
        $destinationHash = $destination ? $this->hashValue($destination) : null;
        $ipHash = $ip ? $this->hashValue($ip) : null;

        $this->guardSendRateLimits($subjectType, $subjectId, $purpose, $destinationHash, $ip);
        $this->guardCooldown($subjectType, $subjectId, $purpose);

        $code = $this->generate($purpose);

        // Phase 3B fix: delivery is no longer attempted inside this
        // transaction (see DeliverOtpNotificationJob's doc comment for
        // why that was unsafe). Only local state changes happen here;
        // the job is dispatched ->afterCommit(), so a real queue defers
        // the actual send until this is durably committed, and even the
        // `sync` driver (tests/local) only runs it once the commit
        // callback fires — never before, and never if the transaction
        // rolls back for any reason.
        DB::transaction(function () use ($subjectType, $subjectId, $purpose, $channel, $destinationHash, $ipHash, $code) {
            Otp::where('verifiable_type', $subjectType)
                ->where('verifiable_id', $subjectId)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $otp = Otp::create([
                'verifiable_type' => $subjectType,
                'verifiable_id' => $subjectId,
                'purpose' => $purpose,
                'channel' => $channel,
                'destination_hash' => $destinationHash,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'max_attempts' => max(1, (int) config('otp.max_attempts', 5)),
                'expires_at' => now()->addMinutes(max(1, (int) config('otp.ttl_minutes', 10))),
                'ip_hash' => $ipHash,
            ]);

            DeliverOtpNotificationJob::dispatch(
                $otp->id,
                $subjectType,
                $subjectId,
                $purpose,
                $channel,
                Crypt::encryptString($code),
            )->afterCommit();
        });

        $this->hitSendRateLimits($subjectType, $subjectId, $purpose, $destinationHash, $ip);
    }

    /**
     * OtpCodeGenerator contract method — resolves the subject by type/id
     * then delegates to the same verification logic verifyForModel() uses.
     */
    public function verify(string $subjectType, int|string $subjectId, string $purpose, string $code): bool
    {
        if (! class_exists($subjectType)) {
            return false;
        }

        $subject = $subjectType::find($subjectId);

        if (! $subject) {
            return false;
        }

        return $this->verifyForModel($subject, $purpose, $code);
    }

    /**
     * Convenience entry point taking the actual model (what every real
     * caller in this codebase has in hand already).
     */
    public function verifyForModel(Model $subject, string $purpose, string $code, ?string $ip = null): bool
    {
        $subjectType = get_class($subject);
        $subjectId = $subject->getKey();

        $this->guardVerifyRateLimit($subjectType, $subjectId, $purpose, $ip);

        return DB::transaction(function () use ($subjectType, $subjectId, $purpose, $code) {
            /** @var Otp|null $otp */
            $otp = Otp::where('verifiable_type', $subjectType)
                ->where('verifiable_id', $subjectId)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $otp) {
                return false;
            }

            if ($otp->attempts >= $otp->max_attempts) {
                $otp->update(['invalidated_at' => now()]);

                return false;
            }

            // Increment attempts before comparing, and persist it inside
            // this same locked transaction — a wrong guess always costs an
            // attempt even if the process crashes immediately after.
            $otp->increment('attempts');

            if (! Hash::check($code, $otp->code_hash)) {
                return false;
            }

            // Atomic, conditional consume: only succeeds if still
            // unconsumed at the moment of the UPDATE. Combined with the
            // row lock above, a second concurrent verify() for the same
            // code can never also return true.
            $affected = Otp::where('id', $otp->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return $affected === 1;
        });
    }

    public function invalidate(Model $subject, string $purpose): void
    {
        Otp::where('verifiable_type', get_class($subject))
            ->where('verifiable_id', $subject->getKey())
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    /**
     * Hard-deletes OTP rows that expired more than a day ago. OTPs are
     * short-lived secrets, not business records, so no soft-delete/restore
     * semantics apply here (unlike BasicCrudService's lifecycle, Phase 1).
     */
    public function cleanupExpired(): int
    {
        return Otp::where('expires_at', '<', now()->subDay())->delete();
    }

    protected function guardCooldown(string $subjectType, $subjectId, string $purpose): void
    {
        $cooldownSeconds = max(0, (int) config('otp.resend_cooldown_seconds', 60));

        if ($cooldownSeconds === 0) {
            return;
        }

        $last = Otp::where('verifiable_type', $subjectType)
            ->where('verifiable_id', $subjectId)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if ($last && $last->created_at->addSeconds($cooldownSeconds)->isFuture()) {
            throw new OtpException('Please wait before requesting another code.', 429);
        }
    }

    protected function guardSendRateLimits(string $subjectType, $subjectId, string $purpose, ?string $destinationHash, ?string $ip): void
    {
        $max = max(1, (int) config('otp.max_sends', 5));
        $decay = max(1, (int) config('otp.send_window_minutes', 60)) * 60;

        if (RateLimiter::tooManyAttempts($this->key('send-subject', $subjectType.':'.$subjectId, $purpose), $max)) {
            throw new OtpException('Too many codes requested for this account. Please try again later.', 429);
        }

        if ($destinationHash && RateLimiter::tooManyAttempts($this->key('send-dest', $destinationHash, $purpose), $max)) {
            throw new OtpException('Too many codes requested for this destination. Please try again later.', 429);
        }

        if ($ip && RateLimiter::tooManyAttempts($this->key('send-ip', $ip, $purpose), $max)) {
            throw new OtpException('Too many codes requested from this network. Please try again later.', 429);
        }
    }

    protected function hitSendRateLimits(string $subjectType, $subjectId, string $purpose, ?string $destinationHash, ?string $ip): void
    {
        $decay = max(1, (int) config('otp.send_window_minutes', 60)) * 60;

        RateLimiter::hit($this->key('send-subject', $subjectType.':'.$subjectId, $purpose), $decay);

        if ($destinationHash) {
            RateLimiter::hit($this->key('send-dest', $destinationHash, $purpose), $decay);
        }

        if ($ip) {
            RateLimiter::hit($this->key('send-ip', $ip, $purpose), $decay);
        }
    }

    protected function guardVerifyRateLimit(string $subjectType, $subjectId, string $purpose, ?string $ip): void
    {
        $max = max(1, (int) config('otp.max_verifies', 10));
        $decay = max(1, (int) config('otp.verify_window_minutes', 15)) * 60;

        $subjectKey = $this->key('verify-subject', $subjectType.':'.$subjectId, $purpose);

        if (RateLimiter::tooManyAttempts($subjectKey, $max)) {
            throw new OtpException('Too many verification attempts. Please try again later.', 429);
        }
        RateLimiter::hit($subjectKey, $decay);

        if ($ip) {
            $ipKey = $this->key('verify-ip', $ip, $purpose);

            if (RateLimiter::tooManyAttempts($ipKey, $max)) {
                throw new OtpException('Too many verification attempts from this network. Please try again later.', 429);
            }
            RateLimiter::hit($ipKey, $decay);
        }
    }

    protected function key(string $bucket, string $subject, string $purpose): string
    {
        return "otp:{$bucket}:{$purpose}:{$subject}";
    }

    protected function hashValue(string $value): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($value)), config('app.key'));
    }

    /**
     * Phase 11D (2026-10-26): real bug fix. "fake" is in config('otp.channels')
     * unconditionally (it has to be, for tests/local dev to work with no
     * provider set up at all) — and `RegisterRequest`'s own validation
     * rule (`Rule::in(array_keys(config('otp.channels')))`) allowed ANY
     * caller, in ANY environment, to explicitly request
     * `otp_delivery_method: "fake"`. FakeOtpChannel::deliver() never
     * throws outside the `testing` environment — it just logs a
     * code-withheld message and returns normally — so a production
     * caller choosing "fake" got back an apparent SUCCESS while nothing
     * was ever actually delivered to the user. This is the single choke
     * point every delivery attempt passes through (OtpService::issue()),
     * so blocking it here closes the gap regardless of which request
     * field or caller chose the channel name.
     */
    protected function resolveChannel(string $channel): OtpChannelProvider
    {
        if ($channel === 'fake' && app()->environment('production')) {
            throw new OtpException(
                'OTP channel "fake" is not available in production — it never actually delivers a code anywhere. Configure a real email/SMS/WhatsApp provider and choose that channel instead.',
                422
            );
        }

        $map = config('otp.channels', []);
        $class = $map[$channel] ?? null;

        if (! $class || ! class_exists($class)) {
            throw new OtpException("OTP channel \"{$channel}\" is not configured.", 422);
        }

        $instance = app($class);

        if (! $instance instanceof OtpChannelProvider) {
            throw new OtpException("OTP channel \"{$channel}\" does not implement OtpChannelProvider.", 500);
        }

        return $instance;
    }
}
