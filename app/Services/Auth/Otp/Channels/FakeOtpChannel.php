<?php

namespace App\Services\Auth\Otp\Channels;

use App\Contracts\Auth\OtpChannelProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3A (2026-10-25). The only channel that "delivers" anywhere outside
 * a real transport — used as the default so tests and local development
 * never accidentally depend on mail/SMS/WhatsApp actually being configured.
 *
 * In the `testing` environment only, the delivered code is captured in a
 * static, in-memory list so tests can assert on it without the code ever
 * touching a log file or any persisted store. Outside `testing`, nothing
 * about the raw code is logged or exposed — only a non-reversible marker
 * that a delivery attempt happened.
 */
class FakeOtpChannel implements OtpChannelProvider
{
    /** @var array<int, array{model: class-string, id: int|string, purpose: string, code: string}> */
    protected static array $captured = [];

    public function deliver(Model $subject, string $purpose, string $code): void
    {
        if (app()->environment('testing')) {
            static::$captured[] = [
                'model' => get_class($subject),
                'id' => $subject->getKey(),
                'purpose' => $purpose,
                'code' => $code,
            ];

            return;
        }

        Log::info('[otp] fake channel delivery (code withheld)', [
            'model' => get_class($subject),
            'id' => $subject->getKey(),
            'purpose' => $purpose,
        ]);
    }

    public function channel(): string
    {
        return 'fake';
    }

    /**
     * Test-only helper: the most recently captured code for a given
     * subject + purpose, or null. Throws outside the testing environment —
     * this must never become a production code-retrieval backdoor.
     */
    public static function lastCodeFor(Model $subject, string $purpose): ?string
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException('FakeOtpChannel::lastCodeFor() is only available in the testing environment.');
        }

        foreach (array_reverse(static::$captured) as $entry) {
            if ($entry['model'] === get_class($subject) && $entry['id'] === $subject->getKey() && $entry['purpose'] === $purpose) {
                return $entry['code'];
            }
        }

        return null;
    }

    public static function reset(): void
    {
        static::$captured = [];
    }
}
