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
