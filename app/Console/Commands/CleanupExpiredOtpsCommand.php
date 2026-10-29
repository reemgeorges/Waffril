<?php

namespace App\Console\Commands;

use App\Services\Auth\OtpService;
use Illuminate\Console\Command;

/**
 * Phase 3A (2026-10-25). Mirrors CleanupExpiredTokensCommand's pattern.
 * Hard-deletes OTP rows that expired more than a day ago (see
 * OtpService::cleanupExpired() for why a hard delete is appropriate here).
 */
class CleanupExpiredOtpsCommand extends Command
{
    protected $signature = 'otp:cleanup-expired';

    protected $description = 'Delete OTP codes that expired more than a day ago';

    public function handle(OtpService $otpService): int
    {
        $count = $otpService->cleanupExpired();

        $this->info("Deleted {$count} expired OTP record(s).");

        return Command::SUCCESS;
    }
}
