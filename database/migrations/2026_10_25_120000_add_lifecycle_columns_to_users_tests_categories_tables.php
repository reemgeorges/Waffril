<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (2026-10-25): additive lifecycle columns.
 *
 * Verified before writing this migration (see docs/starter-roadmap.md, Phase 1
 * section) that the existing `users`, `tests` and `categories` tables, as
 * created by:
 *   - database/migrations/0001_01_01_000000_create_users_table.php
 *   - database/migrations/2026_10_19_073439_create_tests_table.php
 *   - database/migrations/2026_10_19_074649_create_categories_table.php
 * have NEITHER a `deleted_at` NOR an `is_active` column today. Meanwhile
 * app/Models/BaseModel.php and app/Models/BaseAuthModel.php both
 * unconditionally `use SoftDeletes`, which means any withTrashed()/
 * restore()/soft delete() call against User/Test/Category currently throws
 * a SQL "no such column: deleted_at" error. No model/migration anywhere
 * defines an `is_active` column either.
 *
 * This migration is strictly ADDITIVE and reversible:
 *  - adds a nullable `deleted_at` timestamp (the standard softDeletes()
 *    column) to each table, guarded by Schema::hasColumn() so it is a no-op
 *    if the column is somehow already present;
 *  - adds a NOT NULL `is_active` boolean column, defaulting to `true`
 *    (so every pre-existing row is backfilled as active with zero data
 *    mutation needed), to each table, also guarded by hasColumn().
 * It never drops, recreates, truncates, or rewrites data in any existing
 * column. down() only removes the two columns this migration itself added
 * (again guarded by hasColumn()), it never drops a table.
 *
 * This file is NOT executed against any real/configured database connection
 * by this task. It only runs when the test suite boots the sqlite
 * `:memory:` connection configured in phpunit.xml.
 */
return new class extends Migration
{
    /**
     * Tables that currently extend BaseModel/BaseAuthModel (and therefore
     * `use SoftDeletes`) but do not yet have deleted_at/is_active columns.
     *
     * @var array<int, string>
     */
    private array $tables = ['users', 'tests', 'categories'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'deleted_at')) {
                    $blueprint->softDeletes();
                }

                if (! Schema::hasColumn($table, 'is_active')) {
                    $blueprint->boolean('is_active')->default(true);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $blueprint->dropSoftDeletes();
                }

                if (Schema::hasColumn($table, 'is_active')) {
                    $blueprint->dropColumn('is_active');
                }
            });
        }
    }
};
