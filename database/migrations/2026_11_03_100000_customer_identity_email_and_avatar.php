<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI customer identity (2026-11-03) — ADDITIVE / relaxing only:
 *  - users.email becomes OPTIONAL and the legacy unique index on every
 *    email is replaced by a plain index. Uniqueness now applies to VERIFIED
 *    emails only, through the existing uq_users_verified_email index on the
 *    generated `verified_email` column (approved design). No data changes.
 *  - users.avatar: optional profile picture path (public disk).
 *
 * Rollback restores NOT NULL + the unique index only when that cannot fail
 * or lose data (no NULL and no duplicate emails); otherwise it stops with
 * an explanation instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->index('email', 'idx_users_email');
            $table->string('avatar', 255)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        $nulls = DB::table('users')->whereNull('email')->count();
        $duplicates = DB::table('users')->select('email')->whereNotNull('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->count();
        if ($nulls > 0 || $duplicates > 0) {
            throw new RuntimeException("Cannot restore users.email NOT NULL + UNIQUE: {$nulls} customers without email and {$duplicates} duplicated emails. Resolve them deliberately first.");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
            $table->dropIndex('idx_users_email');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->unique('email', 'users_email_unique');
        });
    }
};
