<?php

namespace Tests\Feature\Push;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-16, Phase 11) — `waffrli:fcm-check` reports push
 * readiness without sending anything and without ever printing a private
 * key, an access token or a device token.
 */
class FcmCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privateKey = $pem;
    }

    private function useFcm(?string $privateKey = null): void
    {
        config([
            'waffrli.push.enabled' => true,
            'waffrli.push.driver' => 'fcm',
            'waffrli.push.fcm.credentials' => null,
            'waffrli.push.fcm.project_id' => 'waffrli-test',
            'waffrli.push.fcm.client_email' => 'push-sender@waffrli-test.iam.gserviceaccount.com',
            'waffrli.push.fcm.private_key' => $privateKey ?? $this->privateKey,
            'queue.default' => 'database',
        ]);
    }

    private function deviceToken(): DeviceToken
    {
        return DeviceToken::create([
            'tokenable_type' => (new User)->getMorphClass(), 'tokenable_id' => User::factory()->create()->id,
            'token' => 'secret-device-token-'.str_repeat('x', 40), 'platform' => 'android', 'device_id' => 'd1',
            'is_active' => true, 'last_seen_at' => now(),
        ]);
    }

    public function test_local_checks_with_the_fake_driver_warn_but_do_not_fail_outside_production(): void
    {
        config(['waffrli.push.enabled' => false, 'waffrli.push.driver' => 'fake']);
        Http::fake();

        $this->artisan('waffrli:fcm-check')
            ->expectsOutputToContain('WAFFRLI_PUSH_ENABLED is false')
            ->expectsOutputToContain('No failing checks.')
            ->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_the_fake_driver_fails_in_production(): void
    {
        config(['waffrli.push.enabled' => true, 'waffrli.push.driver' => 'fake']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('waffrli:fcm-check')->assertExitCode(1);
    }

    public function test_valid_credentials_pass_locally_and_nothing_secret_is_printed(): void
    {
        $this->useFcm();
        Http::fake();

        $this->artisan('waffrli:fcm-check')
            ->expectsOutputToContain('Project "waffrli-test", service account pus***@waffrli-test.iam.gserviceaccount.com')
            ->doesntExpectOutputToContain('BEGIN')
            ->doesntExpectOutputToContain('push-sender@')
            ->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_an_unusable_private_key_fails(): void
    {
        $this->useFcm("-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n");

        $this->artisan('waffrli:fcm-check')
            ->expectsOutputToContain('not a valid PEM private key')
            ->doesntExpectOutputToContain('not-a-key')
            ->assertExitCode(1);
    }

    public function test_online_and_validate_only_never_deliver_and_the_report_has_no_secrets(): void
    {
        $this->useFcm();
        Storage::fake('local');
        $token = $this->deviceToken();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.super-secret-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/waffrli-test/messages/fake']),
        ]);

        $this->artisan('waffrli:fcm-check', ['--validate-token' => $token->id, '--report' => true])
            ->expectsOutputToContain('Google issued an access token')
            ->expectsOutputToContain("FCM accepts device token #{$token->id} (android); nothing was delivered.")
            ->doesntExpectOutputToContain('ya29.')
            ->doesntExpectOutputToContain('secret-device-token')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send') && $request['validate_only'] === true);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'messages:send') && empty($request['validate_only']));

        $files = Storage::disk('local')->files('diagnostics');
        $this->assertCount(1, $files);
        $report = Storage::disk('local')->get($files[0]);
        foreach (['BEGIN', 'ya29.', 'secret-device-token', 'push-sender@'] as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
        $this->assertSame('PASS', collect(json_decode($report, true)['results'])->firstWhere('check', 'validate_only')['status']);
    }

    public function test_an_unregistered_device_token_is_reported(): void
    {
        $this->useFcm();
        $token = $this->deviceToken();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $this->artisan('waffrli:fcm-check', ['--validate-token' => $token->id])
            ->expectsOutputToContain('invalid or unregistered')
            ->assertExitCode(1);
    }
}
