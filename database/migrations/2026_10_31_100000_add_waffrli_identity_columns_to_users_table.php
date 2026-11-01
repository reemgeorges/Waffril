<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P1 identity (2026-10-31) — ADDITIVE, non-destructive changes to
 * `users` for the approved customer model.
 *
 * Transitional strategy (no data loss, existing auth APIs keep working):
 *  - The legacy columns (name, password, prefix_phone, phone_number,
 *    boolean `status`, email_status, phone_status, social_id,
 *    otp_delivery_method, activated_at, is_active) and the legacy unique
 *    index on `email` are KEPT until the phone-OTP customer API replaces
 *    the legacy register/login flow. Dropping them now would break
 *    routes/api/v1/user.php and its tests.
 *  - The approved account state is stored in `account_status`
 *    (active|suspended). The approved name was `status`, but the legacy
 *    boolean `status` column still exists and is used by the legacy flow;
 *    it will be renamed when the legacy column is retired.
 *  - `phone` (E.164) is backfilled from prefix_phone + phone_number where
 *    the result is a valid, unique E.164 number; otherwise left NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = SchemaConstraints::isMySql();

        Schema::table('users', function (Blueprint $table) use ($mysql) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('phone', 16)->nullable()->after('phone_number');
            $verified = $table->string('verified_email', 255)->nullable();
            $expression = 'CASE WHEN email_verified_at IS NOT NULL THEN email ELSE NULL END';
            $mysql ? $verified->storedAs($expression) : $verified->virtualAs($expression);
            $table->string('account_status', 16)->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_users_suspended_by_admin')->restrictOnDelete();
            $table->string('suspension_reason', 255)->nullable();
            $table->string('otp_channel', 16)->default('sms');
            $table->foreignId('display_currency_id')->nullable()
                ->constrained('currencies', 'id', 'fk_users_display_currency')->restrictOnDelete();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('recovery_until')->nullable()->index();
            $table->timestamp('anonymized_at')->nullable();

            $table->unique('phone', 'uq_users_phone');
            $table->unique('verified_email', 'uq_users_verified_email');
            $table->index('account_status');
        });

        $this->backfillPhones();

        SchemaConstraints::addCheck('users', 'chk_users_account_status', "account_status IN ('active', 'suspended')");
        SchemaConstraints::addCheck('users', 'chk_users_otp_channel', "otp_channel IN ('sms', 'whatsapp')");
        SchemaConstraints::addCheck('users', 'chk_users_phone_e164', "phone IS NULL OR phone REGEXP '^[+][1-9][0-9]{6,14}$'");
    }

    protected function backfillPhones(): void
    {
        $seen = [];

        DB::table('users')->select(['id', 'prefix_phone', 'phone_number'])
            ->whereNotNull('phone_number')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$seen) {
                foreach ($rows as $row) {
                    $prefix = preg_replace('/\D/', '', (string) $row->prefix_phone);
                    $prefix = preg_replace('/^00/', '', $prefix);
                    $number = ltrim(preg_replace('/\D/', '', (string) $row->phone_number), '0');
                    $e164 = '+'.$prefix.$number;

                    if ($prefix === '' || ! preg_match('/^\+[1-9]\d{6,14}$/', $e164) || isset($seen[$e164])) {
                        continue;
                    }

                    $seen[$e164] = true;
                    DB::table('users')->where('id', $row->id)->update(['phone' => $e164]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_phone');
            $table->dropUnique('uq_users_verified_email');
            $table->dropIndex(['account_status']);
            $table->dropIndex(['recovery_until']);
            // SQLite cannot drop a foreign key by name; by column it rebuilds the table.
            $mysql = SchemaConstraints::isMySql();
            $table->dropForeign($mysql ? 'fk_users_suspended_by_admin' : ['suspended_by_admin_id']);
            $table->dropForeign($mysql ? 'fk_users_display_currency' : ['display_currency_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'phone', 'verified_email', 'account_status', 'suspended_at',
                'suspended_by_admin_id', 'suspension_reason', 'otp_channel', 'display_currency_id',
                'profile_completed_at', 'recovery_until', 'anonymized_at',
            ]);
        });
    }
};
