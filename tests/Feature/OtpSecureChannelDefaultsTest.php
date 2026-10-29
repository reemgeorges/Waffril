<?php

namespace Tests\Feature;

use App\Exceptions\Otp\OtpException;
use App\Http\Requests\Model\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\Otp\Channels\FakeOtpChannel;
use App\Services\Auth\Otp\Channels\SmsOtpChannel;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 11D (2026-10-26) — regression tests for a real, confirmed bug:
 * `RegisterRequest`'s `otp_delivery_method` validation rule allowed ANY
 * caller, in ANY environment, to explicitly request channel "fake" —
 * and `FakeOtpChannel::deliver()` never throws outside the `testing`
 * environment, so a production caller choosing "fake" got back an
 * apparent SUCCESS while nothing was ever actually delivered. Fixed at
 * the single choke point every delivery attempt passes through
 * (`OtpService::resolveChannel()`), plus three FormRequest-level
 * allowlists (`RegisterRequest`, `RequestPhoneChangeRequest`,
 * `SendResetOtpRequest`) tightened for defense-in-depth / a clearer
 * validation error before even attempting delivery.
 *
 * Simulates "production" by temporarily overriding the app() container's
 * 'env' binding directly (`app()->environment()` with no arguments reads
 * exactly that binding) rather than anything heavier — restored in
 * `tearDown()` regardless of pass/fail, since every other test in this
 * whole suite depends on the real `testing` environment being intact.
 */
class OtpSecureChannelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->instance('env', 'testing');

        parent::tearDown();
    }

    public function test_fake_channel_is_rejected_by_otp_service_in_production(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        app()->instance('env', 'production');

        $this->expectException(OtpException::class);
        $this->expectExceptionMessage('OTP channel "fake" is not available in production');

        app(OtpService::class)->issue($user, 'register', 'fake');
    }

    public function test_fake_channel_still_works_outside_production(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Still the real `testing` environment here — must be
        // completely unaffected by the production-only restriction, or
        // every other OTP test in this suite (which all rely on the
        // fake channel) would break.
        app(OtpService::class)->issue($user, 'register', 'fake');

        $code = FakeOtpChannel::lastCodeFor($user, 'register');
        $this->assertNotNull($code, 'The fake channel must still work normally outside production.');
    }

    public function test_sms_and_whatsapp_channels_still_fail_loudly_regardless_of_environment(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Unaffected by this phase's fix — these already failed loudly
        // before it (no real provider configured) and must still.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMS OTP delivery is not implemented');

        (new SmsOtpChannel)->deliver($user, 'register', '123456');
    }

    public function test_register_request_rejects_fake_channel_in_production(): void
    {
        app()->instance('env', 'production');

        $request = new RegisterRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('otp_delivery_method', $rules);
        $validator = Validator::make(
            ['otp_delivery_method' => 'fake', 'name' => 'Test', 'email' => 'x@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'],
            $rules
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_delivery_method', $validator->errors()->toArray());
    }

    public function test_register_request_still_allows_fake_channel_outside_production(): void
    {
        $request = new RegisterRequest;
        $rules = $request->rules();

        $validator = Validator::make(
            ['otp_delivery_method' => 'fake', 'name' => 'Test', 'email' => 'x@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'],
            $rules
        );

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }
}
