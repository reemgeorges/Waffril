<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI P0 (2026-10-30) — driver-aware helpers for constraints that
 * Laravel's Blueprint cannot express.
 *
 * Production target is MySQL 8.0.16+ (CHECK constraints enforced). The
 * fast test suite runs on in-memory SQLite, which cannot
 * `ALTER TABLE ... ADD CONSTRAINT`, so CHECK constraints are only applied
 * on MySQL and are verified by the `@group mysql` suite
 * (tests/Feature/PlatformMySqlConstraintsTest.php). On SQLite the same
 * rules are enforced by the application layer (Form Requests + services)
 * only — see docs/waffrli/p0-foundation.md "SQLite differences".
 *
 * Generated-column expressions are written with `CASE ... END` (valid on
 * both drivers) instead of MySQL's IF(); string concatenation is the only
 * construct that differs and goes through concat().
 */
class SchemaConstraints
{
    public static function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * Adds a named CHECK constraint on MySQL. Constraint names are
     * schema-wide in MySQL, so callers use the chk_<table>_<rule> pattern.
     * Returns false (no-op) on any other driver.
     */
    public static function addCheck(string $table, string $name, string $expression): bool
    {
        if (! static::isMySql()) {
            return false;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");

        return true;
    }

    /**
     * Driver-specific string concatenation for generated-column
     * expressions: CONCAT(a, b) on MySQL, a || b on SQLite.
     */
    public static function concat(string ...$parts): string
    {
        return static::isMySql()
            ? 'CONCAT('.implode(', ', $parts).')'
            : '('.implode(' || ', $parts).')';
    }
}
