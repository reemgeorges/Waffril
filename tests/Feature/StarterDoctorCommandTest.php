<?php

namespace Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Phase 10 (2026-10-26): `php artisan starter:doctor`. Runs against the
 * real test environment (sqlite `:memory:`, the same connection every
 * other test in this suite uses) rather than any fixture — this command
 * is read-only by design (never writes/migrates/seeds), so there is
 * nothing to isolate or clean up afterward.
 */
class StarterDoctorCommandTest extends TestCase
{
    public function test_passes_cleanly_against_the_real_test_environment(): void
    {
        $exitCode = Artisan::call('starter:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Expected a clean run; output:\n{$output}");
        $this->assertStringContainsString('make:module` is registered', $output);
        $this->assertStringContainsString('starter:doctor` is registered', $output);
        $this->assertStringContainsString('implements OtpChannelProvider', $output);
    }

    /**
     * Regression test for a real bug found while first running this
     * command against this project's own dev .env (a MySQL connection to
     * a database that doesn't exist yet): Schema::hasTable() threw an
     * UNCAUGHT QueryException the moment any DB-touching check ran,
     * crashing the whole command and silently skipping every check still
     * queued after it. canConnectToDatabase() now catches that and lets
     * every other, DB-independent check still run and report.
     */
    public function test_an_unreachable_database_is_reported_not_fatal(): void
    {
        $originalConnections = Config::get('database.connections');
        $originalDefault = Config::get('database.default');

        Config::set('database.connections.broken', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1', // nothing listens here
            'database' => 'definitely_does_not_exist',
            'username' => 'nobody',
            'password' => '',
        ]);
        Config::set('database.default', 'broken');

        try {
            $exitCode = Artisan::call('starter:doctor');
            $output = Artisan::output();
        } finally {
            Config::set('database.connections', $originalConnections);
            Config::set('database.default', $originalDefault);
        }

        // The command itself must still complete and report every
        // DB-independent check (never crash partway through) — it's
        // expected to exit non-zero (the DB really is unreachable), but
        // that's a reported FAIL, not an uncaught exception.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot connect to the configured database', $output);
        $this->assertStringContainsString('database unreachable', $output);
        // Checks queued AFTER the DB ones must still have run.
        $this->assertStringContainsString('make:module` is registered', $output);
        $this->assertStringContainsString('implements OtpChannelProvider', $output);
    }

    public function test_an_otp_channel_pointing_at_a_nonexistent_class_is_reported_as_a_failure(): void
    {
        $original = Config::get('otp.channels');

        Config::set('otp.channels.broken', 'App\\Services\\Auth\\Otp\\Channels\\TotallyNotARealClass');

        try {
            $exitCode = Artisan::call('starter:doctor');
            $output = Artisan::output();
        } finally {
            Config::set('otp.channels', $original);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('TotallyNotARealClass', $output);
        $this->assertStringContainsString('[FAIL]', $output);
    }

    public function test_a_middleware_alias_pointing_at_a_nonexistent_class_is_reported_as_a_failure(): void
    {
        app(Router::class)->aliasMiddleware('totally_fake_alias', 'App\\Http\\Middleware\\DoesNotExist');

        $exitCode = Artisan::call('starter:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('totally_fake_alias', $output);
        $this->assertStringContainsString('DoesNotExist', $output);
    }
}
