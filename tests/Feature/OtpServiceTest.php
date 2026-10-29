<?php

namespace Tests\Feature;

use App\Exceptions\Otp\OtpException;
use App\Http\Requests\Model\Auth\ResetPassword\SendResetOtpRequest;
use App\Jobs\Otp\DeliverOtpNotificationJob;
use App\Mail\OtpMail;
use App\Models\Admin;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use App\Services\Auth\OtpService;
use App\Support\OtpChannelHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 3A (2026-10-25) — Secure Dynamic OTP Core.
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 'array' cache driver used in testing (phpunit.xml) is not
        // reset between tests by RefreshDatabase, and RefreshDatabase
        // resets auto-increment ids per test — without this, one test's
        // RateLimiter state (keyed partly by subject id) could leak into
        // the next test that happens to reuse the same id.
        Cache::flush();
        FakeOtpChannel::reset();
    }

    protected function otpService(): OtpService
    {
        return app(OtpService::class);
    }

    public function test_issuing_a_code_stores_only_a_hash_never_plaintext(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->otpService()->issue($user, 'register', 'fake', $user->email);

        $otp = Otp::first();
        $code = FakeOtpChannel::lastCodeFor($user, 'register');

        $this->assertNotNull($code);
        $this->assertNotNull($otp);
        $this->assertNotEquals($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertFalse($otp->getConnection()->getSchemaBuilder()->hasColumn('otps', 'otp'), 'No plaintext otp column should exist.');
    }

    public function test_correct_code_verifies_successfully(): void
    {
        $admin = Admin::factory()->create();

        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $this->assertTrue($this->otpService()->verifyForModel($admin, 'reset_password', $code));
    }

    public function test_wrong_code_fails_and_increments_attempts(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', '000000'));

        $otp = Otp::first();
        $this->assertSame(1, $otp->attempts);
        $this->assertNull($otp->consumed_at);
    }

    public function test_expired_code_fails_even_if_correct(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        Carbon::setTestNow(now()->addMinutes(config('otp.ttl_minutes') + 1));

        $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', $code));

        Carbon::setTestNow();
    }

    public function test_a_consumed_code_cannot_be_replayed(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $this->assertTrue($this->otpService()->verifyForModel($admin, 'reset_password', $code));
        // Second attempt with the SAME code that already succeeded once —
        // simulates the race/replay case: single-use must hold even though
        // the code itself is still correct and not expired.
        $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', $code));
    }

    public function test_exceeding_max_attempts_locks_the_code_even_for_the_correct_value(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $maxAttempts = (int) config('otp.max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', '000000'));
        }

        // The code is now locked out — even the real code must fail.
        $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', $code));

        $otp = Otp::first();
        $this->assertNotNull($otp->invalidated_at);
    }

    public function test_resend_cooldown_blocks_an_immediate_second_issue(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $this->expectException(OtpException::class);
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
    }

    public function test_issuing_again_after_the_cooldown_window_succeeds(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        Carbon::setTestNow(now()->addSeconds(config('otp.resend_cooldown_seconds') + 1));

        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $this->assertSame(2, Otp::count());
        $this->assertNotNull(Otp::oldest('id')->first()->invalidated_at, 'The first code must be invalidated once a new one is issued.');

        Carbon::setTestNow();
    }

    public function test_send_rate_limit_by_subject_is_enforced(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.max_sends' => 2]);

        $admin = Admin::factory()->create();

        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $this->expectException(OtpException::class);
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
    }

    public function test_send_rate_limit_by_destination_catches_abuse_across_different_accounts(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.max_sends' => 1]);

        $adminA = Admin::factory()->create();
        $adminB = Admin::factory()->create();

        $this->otpService()->issue($adminA, 'reset_password', 'fake', 'shared@example.com');

        $this->expectException(OtpException::class);
        $this->otpService()->issue($adminB, 'reset_password', 'fake', 'shared@example.com');
    }

    public function test_verify_rate_limit_caps_requests_across_multiple_codes(): void
    {
        config(['otp.resend_cooldown_seconds' => 0, 'otp.max_verifies' => 2]);

        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $this->otpService()->verifyForModel($admin, 'reset_password', '000000');
        $this->otpService()->verifyForModel($admin, 'reset_password', '000000');

        $this->expectException(OtpException::class);
        $this->otpService()->verifyForModel($admin, 'reset_password', '000000');
    }

    public function test_cross_account_isolation_a_users_code_does_not_verify_for_another_user(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);

        $this->otpService()->issue($userA, 'register', 'fake', $userA->email);
        $code = FakeOtpChannel::lastCodeFor($userA, 'register');

        $this->assertFalse($this->otpService()->verifyForModel($userB, 'register', $code));
        $this->assertTrue($this->otpService()->verifyForModel($userA, 'register', $code));
    }

    public function test_purposes_are_isolated_from_each_other_for_the_same_subject(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->otpService()->issue($user, 'register', 'fake', $user->email);
        $registerCode = FakeOtpChannel::lastCodeFor($user, 'register');

        // A code issued for 'register' must not verify against 'reset_password'.
        $this->assertFalse($this->otpService()->verifyForModel($user, 'reset_password', $registerCode));
        $this->assertTrue($this->otpService()->verifyForModel($user, 'register', $registerCode));
    }

    public function test_invalidate_makes_a_live_code_unverifiable(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $this->otpService()->invalidate($admin, 'reset_password');

        $this->assertFalse($this->otpService()->verifyForModel($admin, 'reset_password', $code));
    }

    public function test_cleanup_expired_deletes_only_long_expired_rows(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        // Not old enough to be cleaned up yet (just past its own TTL, but
        // cleanupExpired() only removes rows expired more than a day ago).
        $this->assertSame(0, $this->otpService()->cleanupExpired());
        $this->assertSame(1, Otp::count());

        Otp::query()->update(['expires_at' => now()->subDays(2)]);

        $this->assertSame(1, $this->otpService()->cleanupExpired());
        $this->assertSame(0, Otp::count());
    }

    public function test_otp_code_generator_contract_verify_resolves_subject_by_type_and_id(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $this->assertTrue($this->otpService()->verify(Admin::class, $admin->id, 'reset_password', $code));
    }

    public function test_email_channel_sends_via_the_real_mail_facade_without_a_real_provider(): void
    {
        Mail::fake();

        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'email', $admin->email);

        Mail::assertSent(OtpMail::class, function ($mail) {
            return $mail->purpose === 'reset_password';
        });
    }

    public function test_sms_and_whatsapp_channels_throw_instead_of_pretending_to_deliver(): void
    {
        $admin = Admin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->otpService()->issue($admin, 'reset_password', 'sms', '15550001111');
    }

    public function test_unknown_channel_is_rejected_with_an_otp_exception(): void
    {
        $admin = Admin::factory()->create();

        $this->expectException(OtpException::class);
        $this->otpService()->issue($admin, 'reset_password', 'carrier-pigeon', $admin->email);
    }

    public function test_otp_channel_helper_backward_compatible_shim_still_works(): void
    {
        $admin = Admin::factory()->create();

        OtpChannelHelper::send($admin, 'reset_password', 'fake');
        $code = FakeOtpChannel::lastCodeFor($admin, 'reset_password');

        $this->assertNotNull($code);
        $this->assertTrue(OtpChannelHelper::verify($admin, 'reset_password', $code));
        $this->assertFalse(OtpChannelHelper::verify($admin, 'reset_password', $code));
    }

    public function test_base_auth_service_send_reset_otp_now_works_end_to_end_for_admin(): void
    {
        // Phase 3B (2026-10-25): purpose is now unified to 'reset_password'
        // (previously 'reset' here, inconsistent with ResetPasswordService —
        // see docs/starter-roadmap.md Phase 3B). This proves the
        // previously-always-fatal call site works end-to-end.
        Mail::fake();

        Route::post('/__test/otp/send-reset-otp', function (SendResetOtpRequest $request) {
            return response()->json(app('AdminAuthService')->sendResetOtp($request));
        });

        $admin = Admin::factory()->create();

        $response = $this->postJson('/__test/otp/send-reset-otp', [
            'actor' => 'admin',
            'email' => $admin->email,
            'channel' => 'email',
        ]);

        $response->assertStatus(200);
        Mail::assertSent(OtpMail::class, function ($mail) {
            return $mail->purpose === 'reset_password';
        });
    }

    public function test_delivery_job_is_idempotent_and_does_not_redeliver_an_already_delivered_code(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $otp = Otp::first();
        $this->assertNotNull($otp->delivered_at);

        FakeOtpChannel::reset();

        // Re-running the job manually (simulating a queue redelivering the
        // same job) must not call the channel again.
        (new DeliverOtpNotificationJob(
            $otp->id,
            Admin::class,
            $admin->id,
            'reset_password',
            'fake',
            Crypt::encryptString('000000'),
        ))->handle();

        $this->assertNull(FakeOtpChannel::lastCodeFor($admin, 'reset_password'));
    }

    public function test_delivery_job_skips_a_code_that_is_no_longer_usable(): void
    {
        $admin = Admin::factory()->create();
        $this->otpService()->issue($admin, 'reset_password', 'fake', $admin->email);

        $otp = Otp::first();
        $otp->update(['invalidated_at' => now(), 'delivered_at' => null]);

        FakeOtpChannel::reset();

        (new DeliverOtpNotificationJob(
            $otp->id,
            Admin::class,
            $admin->id,
            'reset_password',
            'fake',
            Crypt::encryptString('000000'),
        ))->handle();

        $this->assertNull(FakeOtpChannel::lastCodeFor($admin, 'reset_password'));
        $this->assertNull($otp->fresh()->delivered_at);
    }

    public function test_delivery_job_failed_hook_records_a_terminal_failure_state(): void
    {
        $admin = Admin::factory()->create();

        DB::transaction(function () use ($admin) {
            Otp::where('verifiable_type', Admin::class)->where('verifiable_id', $admin->id)->delete();
            $otp = Otp::create([
                'verifiable_type' => Admin::class,
                'verifiable_id' => $admin->id,
                'purpose' => 'reset_password',
                'channel' => 'sms',
                'code_hash' => Hash::make('123456'),
                'attempts' => 0,
                'max_attempts' => 5,
                'expires_at' => now()->addMinutes(10),
            ]);

            $job = new DeliverOtpNotificationJob(
                $otp->id,
                Admin::class,
                $admin->id,
                'reset_password',
                'sms',
                Crypt::encryptString('123456'),
            );

            $job->failed(new \RuntimeException('SMS gateway unreachable'));
        });

        $otp = Otp::first();
        $this->assertNotNull($otp->delivery_failed_at);
        $this->assertStringContainsString('Permanently failed after', $otp->last_delivery_error);
    }
}
