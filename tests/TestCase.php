<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // WAFFRLI (2026-11-03): messages "sent" by the recording broadcaster.
        \App\Support\Broadcasting\RecordingBroadcaster::reset();
        \App\Services\Push\FakePushTransport::reset();
        \App\Services\Push\ChannelPresence::$occupiedForTesting = [];
    }

    /**
     * WAFFRLI MySQL verification (2026-10-31): generator and E2E tests run a
     * real `migrate` instead of RefreshDatabase. On MySQL that DDL commits
     * implicitly, so the rows they create would leak into later tests (the
     * in-memory SQLite run never noticed). Call from tearDown(); it only
     * acts on a MySQL connection in the testing environment and keeps the
     * schema, emptying every table except `migrations`.
     */
    protected function purgeCommittedRowsOnMySql(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::withoutForeignKeyConstraints(function () {
            foreach (Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
        });
    }
}
