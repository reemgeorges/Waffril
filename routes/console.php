<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// WAFFRLI (2026-10-31): operational schedule (requires `php artisan schedule:work`
// or a cron running `schedule:run`). Neither task cancels orders or moves money.
Schedule::command('waffrli:flag-urgent-orders')->everyMinute()->withoutOverlapping();
Schedule::command('waffrli:sync-plus-cycles')->everyFiveMinutes()->withoutOverlapping();

// WAFFRLI (2026-11-04): housekeeping that existed but was never scheduled.
// Deletes only OTP rows expired for more than a day and Sanctum tokens past
// their own expires_at (off-peak, 03:15 / 03:25 Asia/Damascus).
Schedule::command('otp:cleanup-expired')->dailyAt('00:15')->withoutOverlapping();
Schedule::command('tokens:cleanup-expired')->dailyAt('00:25')->withoutOverlapping();
