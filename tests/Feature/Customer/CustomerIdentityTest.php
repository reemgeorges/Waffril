<?php

namespace Tests\Feature\Customer;

use App\Mail\OtpMail;
use App\Models\Admin;
use App\Models\City;
use App\Models\Currency;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Otp;
use App\Models\User;
use App\Services\Messaging\FakeTextGateway;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * WAFFRLI customer API (2026-11-03) — phone OTP identity, sessions,
 * suspension, deletion/recovery, profile and addresses.
 */
class CustomerIdentityTest extends CustomerTestCase
{
    public function test_registration_requires_a_verified_phone_and_names(): void
    {
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => '0933 111 222'])->assertOk()
            ->assertJsonPath('data.channel', 'sms')->assertJsonMissingPath('data.code');
        $phone = '+963933111222';
        $this->assertSame(0, User::where('phone', $phone)->count(), 'No account before verification.');
        $otp = Otp::query()->where('verifiable_type', 'customer_phone')->latest('id')->first();
        $this->assertNull($otp->verifiable_id);
        $this->assertStringNotContainsString($this->otpFor($phone), (string) $otp->code_hash);

        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => '000000'])->assertStatus(422);
        $verify = $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => $this->otpFor($phone)])
            ->assertOk()->assertJsonPath('data.status', 'registration_required');
        $registration = $verify->json('data.registration_token');

        // Single use: the same code cannot be replayed.
        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => $this->otpFor($phone)])->assertStatus(422);

        $this->postJson('/api/v1/customer/auth/register', ['registration_token' => $registration, 'first_name' => 'Rana'])->assertStatus(400);
        $register = $this->postJson('/api/v1/customer/auth/register', ['registration_token' => $registration, 'first_name' => 'Rana', 'last_name' => 'Saad', 'device_id' => 'phone-a'])
            ->assertOk()->assertJsonPath('data.status', 'registered')->assertJsonPath('data.user.phone', $phone)
            ->assertJsonPath('data.user.profile_completed', true)->assertJsonPath('data.user.email', null);
        $this->assertStringNotContainsString('password', strtolower($register->getContent()));

        // Registration token is single-use.
        $this->postJson('/api/v1/customer/auth/register', ['registration_token' => $registration, 'first_name' => 'X', 'last_name' => 'Y'])->assertStatus(422);
        $this->postJson('/api/v1/customer/auth/register', ['registration_token' => 'forged', 'first_name' => 'X', 'last_name' => 'Y'])->assertStatus(422);

        $user = User::where('phone', $phone)->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
        $this->api($register->json('data.token'))->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.user.first_name', 'Rana');
    }

    public function test_otp_cooldown_attempt_ceiling_and_expiry(): void
    {
        $phone = '+963944555666';
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $phone])->assertOk();
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $phone])->assertStatus(429);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => '111111'])->assertStatus(422);
        }
        // Ceiling reached: even the right code fails now.
        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => $this->otpFor($phone)])->assertStatus(422);

        $this->clearOtpLimits();
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $phone])->assertOk();
        $code = $this->otpFor($phone);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $phone, 'code' => $code])->assertStatus(422);

        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => 'abc'])->assertStatus(400);
    }

    public function test_whatsapp_fallback_and_twilio_transport(): void
    {
        FakeTextGateway::$failSmsFor = ['+963955000111'];
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => '+963955000111'])->assertOk()->assertJsonPath('data.channel', 'whatsapp');

        config(['waffrli.messaging.driver' => 'twilio', 'waffrli.messaging.twilio' => [
            'account_sid' => 'ACtest', 'auth_token' => 'test-token', 'sms_from' => '+15005550006', 'whatsapp_from' => '+14155238886', 'timeout' => 5,
        ]]);
        $mode = 'ok';
        Http::fake(function ($request) use (&$mode) {
            $whatsapp = str_starts_with((string) $request['To'], 'whatsapp:');

            return match ($mode) {
                'ok' => Http::response(['sid' => 'SM1'], 201),
                'sms-down' => Http::response([], $whatsapp ? 201 : 500),
                default => Http::response([], 500),
            };
        });

        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => '+963955000222'])->assertOk()->assertJsonPath('data.channel', 'sms');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/ACtest/Messages.json') && $request['To'] === '+963955000222');

        $mode = 'sms-down';
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => '+963955000333'])->assertOk()->assertJsonPath('data.channel', 'whatsapp');
        Http::assertSent(fn ($request) => $request['To'] === 'whatsapp:+963955000333' && $request['From'] === 'whatsapp:+14155238886');

        $mode = 'all-down';
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => '+963955000444'])->assertStatus(503);
        $this->assertNotNull(Otp::query()->latest('id')->first()->invalidated_at, 'An undelivered code is invalidated.');
    }

    public function test_one_active_device_and_guard_isolation(): void
    {
        $user = $this->demoCustomer(1);
        DeviceToken::create(['tokenable_type' => $user->getMorphClass(), 'tokenable_id' => $user->id, 'token' => 'push-a', 'platform' => 'android', 'device_id' => 'device-1', 'is_active' => true]);
        $first = $this->loginCustomer($user, 'device-1');
        $this->clearOtpLimits();
        $second = $this->loginCustomer($user, 'device-2');

        $this->api($first)->getJson('/api/v1/customer/me')->assertStatus(401);
        $this->api($second)->getJson('/api/v1/customer/me')->assertOk();
        $this->assertFalse(DeviceToken::where('token', 'push-a')->first()->is_active, 'Push token of the old device is revoked.');

        $driver = Driver::firstOrFail();
        $this->api($driver->createToken('t', ['driver', '*'])->plainTextToken)->getJson('/api/v1/customer/me')->assertStatus(401);
        $this->api(Admin::factory()->create()->createToken('t', ['admin', '*'])->plainTextToken)->getJson('/api/v1/customer/me')->assertStatus(401);
        $this->api($second)->getJson('/api/v1/driver/me')->assertStatus(401);

        $this->api($second)->postJson('/api/v1/customer/auth/logout')->assertOk();
        $this->api($second)->getJson('/api/v1/customer/me')->assertStatus(401);
    }

    public function test_suspended_customers_cannot_log_in_or_act(): void
    {
        $user = $this->demoCustomer(2);
        $token = $this->loginCustomer($user);
        $user->forceFill(['account_status' => User::ACCOUNT_SUSPENDED])->save();

        $this->api($token)->getJson('/api/v1/customer/me')->assertStatus(403);
        $this->clearOtpLimits();
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $user->phone])->assertOk();
        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $user->phone, 'code' => $this->otpFor($user->phone)])->assertStatus(403);
    }

    public function test_deletion_window_and_recovery(): void
    {
        $user = $this->demoCustomer(7); // no open orders in the demo data after cancellation below
        Order::where('user_id', $user->id)->whereNotIn('status', ['delivered', 'cancelled'])->update(['status' => 'cancelled']);
        $token = $this->loginCustomer($user);

        $this->api($token)->deleteJson('/api/v1/customer/me')->assertOk()->assertJsonStructure(['data' => ['recovery_until']]);
        $this->assertSoftDeleted($user);
        $this->api($token)->getJson('/api/v1/customer/me')->assertStatus(401);

        $this->clearOtpLimits();
        $this->postJson('/api/v1/customer/auth/otp/request', ['phone' => $user->phone])->assertOk();
        $this->postJson('/api/v1/customer/auth/otp/verify', ['phone' => $user->phone, 'code' => $this->otpFor($user->phone)])->assertStatus(409);

        $this->postJson('/api/v1/customer/auth/recovery/request', ['phone' => $user->phone])->assertOk();
        $this->postJson('/api/v1/customer/auth/recovery/verify', ['phone' => $user->phone, 'code' => '000000'])->assertStatus(422);
        $recovered = $this->postJson('/api/v1/customer/auth/recovery/verify', ['phone' => $user->phone, 'code' => $this->otpFor($user->phone)])->assertOk();
        $this->assertNull($user->fresh()->deleted_at);
        $this->api($recovered->json('data.token'))->getJson('/api/v1/customer/me')->assertOk();

        // Unknown phone: same answer, nothing sent.
        $this->postJson('/api/v1/customer/auth/recovery/request', ['phone' => '+963966000000'])->assertOk()->assertJsonPath('data.channel', null);
        $this->assertNull(FakeTextGateway::last('+963966000000'));
    }

    public function test_deletion_is_blocked_with_open_orders(): void
    {
        $user = $this->demoCustomer(1); // has live demo orders
        $this->api($this->loginCustomer($user))->deleteJson('/api/v1/customer/me')->assertStatus(409);
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_profile_email_avatar_and_currency(): void
    {
        Mail::fake();
        $user = $this->demoCustomer(3);
        $other = $this->demoCustomer(4);
        $token = $this->loginCustomer($user);

        $this->api($token)->patchJson('/api/v1/customer/me', ['first_name' => 'Maya2', 'phone' => '+10000000', 'email' => $other->email])
            ->assertOk()->assertJsonPath('data.user.first_name', 'Maya2')->assertJsonPath('data.user.phone', $user->phone)->assertJsonPath('data.user.email_verified', false);
        // Verified-only uniqueness: $other's email is verified, so ours cannot be verified.
        $this->api($token)->postJson('/api/v1/customer/me/email/verification')->assertStatus(409);

        $this->api($token)->patchJson('/api/v1/customer/me', ['email' => 'maya.new@example.test'])->assertOk();
        $this->api($token)->postJson('/api/v1/customer/me/email/verification')->assertOk();
        $code = null;
        Mail::assertSent(OtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->api($token)->postJson('/api/v1/customer/me/email/verify', ['code' => $code])->assertOk()->assertJsonPath('data.user.email_verified', true);

        $this->api($token)->post('/api/v1/customer/me/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])->assertOk();
        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);
        $this->api($token)->deleteJson('/api/v1/customer/me/avatar')->assertOk();
        Storage::disk('public')->assertMissing($path);

        $syp = Currency::where('code', 'SYP')->firstOrFail();
        $this->api($token)->patchJson('/api/v1/customer/me', ['display_currency_id' => $syp->id])->assertOk()->assertJsonPath('data.user.display_currency.code', 'SYP');
    }

    public function test_addresses_are_private_and_define_the_delivery_city(): void
    {
        $user = $this->demoCustomer(5);
        $token = $this->loginCustomer($user);

        $created = $this->api($token)->postJson('/api/v1/customer/addresses', ['label' => 'Office', 'address_line' => 'Aleppo office', 'lat' => 36.2050, 'lng' => 37.1400, 'is_default' => true])
            ->assertOk()->assertJsonPath('data.address.city.name', 'Aleppo')->assertJsonPath('data.address.is_default', true);
        $this->api($token)->getJson('/api/v1/customer/context')->assertOk()->assertJsonPath('data.delivery_city.name', 'Aleppo');
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());

        $this->api($token)->postJson('/api/v1/customer/addresses', ['address_line' => 'Sea', 'lat' => 35.0, 'lng' => 30.0])->assertStatus(422);
        $damascus = City::where('name->en', 'Damascus')->firstOrFail();
        $this->api($token)->postJson('/api/v1/customer/addresses', ['address_line' => 'x', 'lat' => 36.2050, 'lng' => 37.1400, 'city_id' => $damascus->id])->assertStatus(422);

        $foreign = $this->demoCustomer(6)->addresses()->firstOrFail();
        $this->api($token)->patchJson("/api/v1/customer/addresses/{$foreign->id}", ['label' => 'mine'])->assertStatus(404);
        $this->api($token)->deleteJson("/api/v1/customer/addresses/{$foreign->id}")->assertStatus(404);

        $id = $created->json('data.address.id');
        $this->api($token)->deleteJson("/api/v1/customer/addresses/{$id}")->assertOk();
        $this->api($token)->getJson('/api/v1/customer/context')->assertOk()->assertJsonPath('data.delivery_city.name', 'Damascus');
    }

    public function test_dev_otp_peek_is_guarded(): void
    {
        // No peek token configured in the test environment → the route is not registered at all.
        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'api/v1/dev/otp'));
    }
}
