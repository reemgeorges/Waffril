<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** WAFFRLI (2026-11-04) — every recurring job the platform relies on is scheduled. */
class ScheduleTest extends TestCase
{
    public function test_required_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

        foreach (['waffrli:flag-urgent-orders', 'waffrli:sync-plus-cycles', 'otp:cleanup-expired', 'tokens:cleanup-expired'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
    }
}
