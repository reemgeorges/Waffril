<?php

namespace App\Jobs\Otp;

use App\Contracts\Auth\OtpChannelProvider;
use App\Models\Otp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3B (2026-10-25) — transactional-outbox safety fix. Phase 3C
 * (2026-10-25) — retry/duplicate-delivery hardening.
 *
 * Phase 3A's App\Services\Auth\OtpService::issue() called the channel
 * provider's deliver() SYNCHRONOUSLY, INSIDE the same DB transaction that
 * created the Otp row. That is unsafe for a real external side effect:
 * sending an irreversible message (an email, an SMS) from inside a
 * transaction means (a) the transaction holds its lock/connection open for
 * however long that external I/O takes, and (b) if something ELSE later in
 * the same transaction fails and rolls back, the message was already sent
 * for a code that, from the database's point of view, never existed.
 *
 * This job is dispatched via `->afterCommit()` from inside that same
 * transaction instead: with a real queue (database/redis), Laravel defers
 * the actual push until the transaction commits; with the `sync` driver
 * (this project's test/local default), it still only runs once the commit
 * callback fires, i.e. after the row is durably persisted, never before.
 *
 * The raw code is never persisted to the queue backend in the clear: the
 * caller passes it already encrypted (Crypt::encryptString), so even a
 * durable `jobs` table row — which a real queue driver must write to
 * record the pending job — holds ciphertext, not the code itself. It is
 * decrypted only in memory, here, immediately before delivery.
 *
 * Phase 3C hardening (duplicate delivery / failed-send recovery — a real
 * queue driver retries a failed job up to $tries times):
 *  - Bounded retries ($tries/$backoff below) instead of Laravel's
 *    unbounded default, so a permanently-misconfigured channel fails
 *    definitively rather than hammering it forever.
 *  - Skips delivery entirely if the code is no longer usable (already
 *    consumed, invalidated by a newer code, or expired) by the time this
 *    job actually runs — a resend requested quickly after the first can
 *    otherwise race the queue, and there is no point delivering a code
 *    that is already dead.
 *  - The ONLY case genuinely at risk of delivering twice is: deliver()
 *    truly succeeds, but the very next line (recording delivered_at)
 *    fails before committing, so a retry can't see that success and tries
 *    again. This is accepted, not "fixed" away: redelivering the SAME
 *    still-valid OTP code is a UX nuisance, not a security issue — the
 *    code is still single-use at *verify* time regardless of how many
 *    times it was sent. Guarding against this window would require a
 *    separate at-least-once/exactly-once delivery ledger, disproportionate
 *    to what a 6-digit, single-use, minutes-lived code actually needs.
 *  - Failure recording is itself defensive (wrapped so a DB hiccup while
 *    recording a failure can't mask or replace the original exception),
 *    and failed() records a distinct terminal state once ALL retries are
 *    exhausted — fails closed, never silently swallowed.
 */
class DeliverOtpNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $otpId,
        public string $subjectType,
        public int|string $subjectId,
        public string $purpose,
        public string $channel,
        public string $encryptedCode,
    ) {}

    public function handle(): void
    {
        /** @var Otp|null $otp */
        $otp = Otp::find($this->otpId);

        if (! $otp) {
            return;
        }

        if ($otp->delivered_at) {
            // Already confirmed delivered by an earlier attempt — never
            // re-deliver just because the queue re-ran this job.
            return;
        }

        if (! $otp->isUsable()) {
            // Consumed, invalidated (superseded by a newer code), or
            // expired by the time this job actually ran — delivering it
            // now would only confuse the recipient.
            return;
        }

        $subject = $this->subjectType::find($this->subjectId);

        if (! $subject) {
            $this->recordFailure($otp, 'Subject no longer exists.');

            return;
        }

        $map = config('otp.channels', []);
        $class = $map[$this->channel] ?? null;
        $provider = ($class && class_exists($class)) ? app($class) : null;

        if (! $provider instanceof OtpChannelProvider) {
            $message = "OTP channel \"{$this->channel}\" is not configured.";
            $this->recordFailure($otp, $message);

            throw new \RuntimeException($message);
        }

        try {
            $code = Crypt::decryptString($this->encryptedCode);
            $provider->deliver($subject, $this->purpose, $code);
            $otp->update(['delivered_at' => now()]);
        } catch (\Throwable $e) {
            $this->recordFailure($otp, mb_substr($e->getMessage(), 0, 500));

            throw $e;
        }
    }

    /**
     * Called once by the queue after $tries attempts have all failed —
     * the distinct "we gave up" terminal state, separate from a single
     * attempt's failure (which handle()'s catch already records and may
     * still be retried).
     */
    public function failed(\Throwable $exception): void
    {
        $otp = Otp::find($this->otpId);

        if ($otp) {
            $this->recordFailure($otp, 'Permanently failed after '.$this->tries.' attempt(s): '.mb_substr($exception->getMessage(), 0, 450));
        }
    }

    /**
     * Wrapped so a failure while recording a failure (e.g. a transient DB
     * hiccup) never masks or replaces the exception that actually caused
     * delivery to fail — it's logged instead, best-effort.
     */
    protected function recordFailure(Otp $otp, string $message): void
    {
        try {
            $otp->update(['delivery_failed_at' => now(), 'last_delivery_error' => $message]);
        } catch (\Throwable $e) {
            Log::warning('[otp] failed to record OTP delivery failure', [
                'otp_id' => $otp->id,
                'original_error' => $message,
                'recording_error' => $e->getMessage(),
            ]);
        }
    }
}
