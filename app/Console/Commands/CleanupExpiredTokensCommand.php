<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
class CleanupExpiredTokensCommand extends Command
{
    protected $signature = 'tokens:cleanup-expired';
    protected $description = 'Delete expired Sanctum tokens and associated device tokens';

    public function handle()
    {
        $now = Carbon::now();

        $expiredTokens = PersonalAccessToken::where('expires_at', '<', $now)->get();

        $this->info("Found {$expiredTokens->count()} expired tokens.");

        foreach ($expiredTokens as $token)
            $token->delete();

        return Command::SUCCESS;
    }
}
