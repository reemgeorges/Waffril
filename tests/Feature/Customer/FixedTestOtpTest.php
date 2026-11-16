<?php

namespace Tests\Feature\Customer;

use App\Models\Otp;
use App\Services\Customer\Auth\FixedTestOtp;
use App\Services\Messaging\FakeTextGateway;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/**
 * WAFFRLI (2026-11-16) — optional fixed customer OTP for local app testing
 * (config waffrli.dev.fixed_otp, App\Services\Customer\Auth\FixedTestOtp).
 */
class FixedTestOtpTest extends CustomerTestCase
{
    protected bool $seedDemo = false;

    protected const TEST_PHONE = '+963944000001';

    protected const OTHER_PHONE = '+963944000002';

    /** @var list<string> */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    protected function enable(array $phones = [self::TEST_PHONE], string $code = '123456'): void
    {
        config(['waffrli.dev.fixed_otp' => ['enabled' => true, 'code' => $code, 'phones' => $phones]]);
    }

    protected function request(string $phone, string $path = 'auth/otp/request')
    {
        return $this->postJson("/api/v1/customer/{$path}", ['phone' => $phone]);
    }

    protected function verify(string $phone, string $code, string $path = 'auth/otp/verify')
    {
        return $this->postJson("/api/v1/customer/{$path}", ['phone' => $phone, 'code' => $code, 'device_id' => 'test-device']);
    }

    public function test_an_allowlisted_phone_signs_in_with_the_fixed_code_and_nothing_is_sent(): void
    {
        $this->enable();

        $response = $this->request(self::TEST_PHONE)->assertOk();
        // Unchanged contract: same keys and the normal channel name.
        $response->assertJsonPath('data.channel', 'sms')->assertJsonStructure(['data' => ['message', 'channel', 'expires_in', 'resend_in'], 'status', 'error', 'statusCode']);
        $this->assertStringNotContainsString('123456', $response->getContent(), 'The code is never returned.');
        $this->assertNull(FakeTextGateway::last(self::TEST_PHONE), 'No SMS or WhatsApp in fixed mode.');

        $otp = Otp::query()->latest('id')->firstOrFail();
        $this->assertSame(FixedTestOtp::CHANNEL, $otp->channel);
        $this->assertNotSame('123456', $otp->code_hash, 'Stored hashed, never in clear.');
        $this->assertTrue(Hash::check('123456', $otp->code_hash));

        $this->verify(self::TEST_PHONE, '123456')->assertOk()->assertJsonPath('data.status', 'registration_required');

        // Single use: the same code cannot be used twice.
        $this->verify(self::TEST_PHONE, '123456')->assertStatus(422);

        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('123456', $line, 'The code never reaches the log.');
        }
    }

    public function test_the_fixed_code_expires_like_any_other_code(): void
    {
        $this->enable();
        $this->request(self::TEST_PHONE)->assertOk();
        $this->travel(11)->minutes();
        $this->verify(self::TEST_PHONE, '123456')->assertStatus(422);
    }

    public function test_cooldown_rate_limits_and_attempt_ceiling_still_apply(): void
    {
        $this->enable();
        $this->request(self::TEST_PHONE)->assertOk();
        $this->request(self::TEST_PHONE)->assertStatus(429);

        foreach (range(1, 5) as $i) {
            $this->verify(self::TEST_PHONE, '000000')->assertStatus(422);
        }
        $this->verify(self::TEST_PHONE, '123456')->assertStatus(422, 'Attempt ceiling reached: even the fixed code fails.');
    }

    public function test_purposes_stay_isolated(): void
    {
        $this->enable();
        $this->request(self::TEST_PHONE)->assertOk();
        // A login code does not open account recovery.
        $this->verify(self::TEST_PHONE, '123456', 'auth/recovery/verify')->assertStatus(422);
    }

    public function test_a_phone_outside_the_allowlist_gets_a_random_code_by_sms(): void
    {
        $this->enable();
        $this->request(self::OTHER_PHONE)->assertOk();

        $sent = FakeTextGateway::last(self::OTHER_PHONE);
        $this->assertNotNull($sent, 'Normal SMS path.');
        $this->assertSame('sms', Otp::query()->latest('id')->value('channel'));
        $code = $this->otpFor(self::OTHER_PHONE);
        if ($code !== '123456') {
            $this->verify(self::OTHER_PHONE, '123456')->assertStatus(422);
        }
        $this->verify(self::OTHER_PHONE, $code)->assertOk();
    }

    public function test_disabled_mode_keeps_random_codes_and_real_messaging(): void
    {
        config(['waffrli.dev.fixed_otp' => ['enabled' => false, 'code' => '123456', 'phones' => [self::TEST_PHONE]]]);
        $this->request(self::TEST_PHONE)->assertOk();

        $this->assertNotNull(FakeTextGateway::last(self::TEST_PHONE));
        $this->assertSame('sms', Otp::query()->latest('id')->value('channel'));
        $this->assertNull(FixedTestOtp::codeFor(self::TEST_PHONE));
    }

    public function test_production_never_uses_the_fixed_code_whatever_the_flags(): void
    {
        $this->enable();
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(FixedTestOtp::environmentAllows());
        $this->assertNull(FixedTestOtp::codeFor(self::TEST_PHONE));

        // Other non-local environments are refused too.
        $this->app->detectEnvironment(fn () => 'staging');
        $this->assertNull(FixedTestOtp::codeFor(self::TEST_PHONE));
    }

    public function test_invalid_codes_and_an_empty_allowlist_disable_the_mode(): void
    {
        $this->enable([], '123456');
        $this->assertNull(FixedTestOtp::codeFor(self::TEST_PHONE), 'No allowlist: no fixed code for anyone.');

        $this->enable([self::TEST_PHONE], '12ab');
        $this->assertNull(FixedTestOtp::codeFor(self::TEST_PHONE), 'A non-numeric code is ignored.');

        // Allowlist entries are normalized like customer phones.
        $this->enable(['00963 944-000-001']);
        $this->assertSame('123456', FixedTestOtp::codeFor(self::TEST_PHONE));
    }
}
