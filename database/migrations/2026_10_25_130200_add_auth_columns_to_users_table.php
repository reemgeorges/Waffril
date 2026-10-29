<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A (2026-10-25) — Configurable Authentication Foundation.
 *
 * App\Services\Functional\UserAuthService and App\Services\Basic\BaseAuthService
 * already read/write these columns on `users` (status, social_id,
 * prefix_phone, phone_number, otp_delivery_method, activated_at,
 * email_status, phone_status, phone_verified_at, language) but the table
 * never had them — every call already hits a QueryException ("no such
 * column"). All additions are nullable or boolean-with-default, so existing
 * rows remain valid with no backfill required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->boolean('status')->default(false);
            }
            if (! Schema::hasColumn('users', 'social_id')) {
                $table->string('social_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'prefix_phone')) {
                $table->string('prefix_phone', 10)->nullable();
            }
            if (! Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number', 30)->nullable();
            }
            if (! Schema::hasColumn('users', 'otp_delivery_method')) {
                $table->string('otp_delivery_method')->nullable();
            }
            if (! Schema::hasColumn('users', 'activated_at')) {
                $table->timestamp('activated_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'email_status')) {
                $table->boolean('email_status')->default(false);
            }
            if (! Schema::hasColumn('users', 'phone_status')) {
                $table->boolean('phone_status')->default(false);
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'language')) {
                $table->string('language', 10)->nullable()->default('en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'status', 'social_id', 'prefix_phone', 'phone_number',
                'otp_delivery_method', 'activated_at', 'email_status',
                'phone_status', 'phone_verified_at', 'language',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
