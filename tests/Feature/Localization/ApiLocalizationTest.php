<?php

namespace Tests\Feature\Localization;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-04) — Customer and Driver APIs answer in Arabic or
 * English from Accept-Language (default config('app.locale')): labels,
 * messages and errors are translated; machine values (statuses, types,
 * property names) never are.
 */
class ApiLocalizationTest extends CustomerTestCase
{
    protected function as(string $locale, string $token): static
    {
        return $this->api($token)->withHeader('Accept-Language', $locale);
    }

    public function test_order_values_are_stable_and_labels_follow_the_language(): void
    {
        $order = Order::where('order_number', 'DEMO-0033')->firstOrFail(); // picked up
        $token = $this->loginCustomer(User::findOrFail($order->user_id));

        $ar = $this->as('ar', $token)->getJson("/api/v1/customer/orders/{$order->id}")->assertOk()->assertHeader('Content-Language', 'ar')->json('data.order');
        $en = $this->as('en', $token)->getJson("/api/v1/customer/orders/{$order->id}")->assertOk()->assertHeader('Content-Language', 'en')->json('data.order');

        $this->assertSame('picked_up', $ar['status']);
        $this->assertSame($ar['status'], $en['status'], 'Machine values never change with the language.');
        $this->assertSame(__('admin.order_status.picked_up', [], 'ar'), $ar['status_label']);
        $this->assertSame(__('admin.order_status.picked_up', [], 'en'), $en['status_label']);
        $this->assertNotSame($ar['status_label'], $en['status_label']);
        $this->assertSame(__('admin.orders.type_'.$ar['type'], [], 'ar'), $ar['type_label']);
        $this->assertSame(array_keys($ar), array_keys($en), 'Same property names in both languages.');
        foreach ($ar['payments'] as $i => $payment) {
            $this->assertSame(__('admin.payments.status_'.$payment['status'], [], 'ar'), $payment['status_label']);
            $this->assertSame($payment['status'], $en['payments'][$i]['status']);
        }
        foreach ($ar['status_history'] as $row) {
            $this->assertSame(__('admin.order_status.'.$row['to'], [], 'ar'), $row['to_label']);
        }
    }

    public function test_wallet_plus_and_profile_labels(): void
    {
        $token = $this->loginCustomer($this->demoCustomer(1));
        foreach (['ar', 'en'] as $locale) {
            $tx = $this->as($locale, $token)->getJson('/api/v1/customer/wallet/transactions')->assertOk()->json('data.transactions.0');
            if ($tx) {
                $this->assertSame(__('admin.wallets.type_'.$tx['type'], [], $locale), $tx['type_label']);
                $this->assertSame(__('admin.wallets.direction_'.$tx['direction'], [], $locale), $tx['direction_label']);
            }
            $me = $this->as($locale, $token)->getJson('/api/v1/customer/me')->assertOk()->json('data.user');
            $this->assertSame(__('admin.customers.status_active', [], $locale), $me['account_status_label']);

            $plus = $this->as($locale, $token)->getJson('/api/v1/customer/plus/eligible-discounts')->assertOk()->json('data');
            $this->assertSame(__('messages.customer.plus_priority_note', [], $locale), $plus['note']);
        }
    }

    public function test_business_errors_and_authentication_errors_are_localized(): void
    {
        $user = $this->demoCustomer(10);
        $user->addresses()->delete();
        $token = $this->loginCustomer($user);
        foreach (['ar', 'en'] as $locale) {
            $error = $this->as($locale, $token)->getJson('/api/v1/customer/branches')->assertStatus(409)->json('error');
            $this->assertSame(__('messages.customer.no_delivery_address', [], $locale), $error);

            $this->app['auth']->forgetGuards();
            $unauth = $this->flushHeaders()->withHeaders(['Accept-Language' => $locale, 'Accept' => 'application/json'])->getJson('/api/v1/customer/me')->assertStatus(401)->json('error');
            $this->assertSame(__('messages.unauthenticated', [], $locale), $unauth);
            $this->assertStringNotContainsString('messages.', $unauth, 'Never a raw key.');
        }
    }

    public function test_language_negotiation_and_default(): void
    {
        $this->withHeader('Accept-Language', 'ar-SY,ar;q=0.9,en;q=0.5')->postJson('/api/v1/customer/auth/otp/request', [])->assertHeader('Content-Language', 'ar');
        $this->flushHeaders()->postJson('/api/v1/customer/auth/otp/request', [])->assertHeader('Content-Language', config('app.locale'));
        $this->flushHeaders()->withHeader('Accept-Language', 'fr-FR')->postJson('/api/v1/customer/auth/otp/request', [])->assertHeader('Content-Language', config('app.locale'));
    }

    public function test_driver_api_labels_follow_the_language(): void
    {
        $driver = DemoPeopleSeeder::driver(1);
        $driver->forceFill(['password' => 'test-only-password-1'])->save();
        $token = $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->json('data.token');

        foreach (['ar', 'en'] as $locale) {
            $me = $this->as($locale, $token)->getJson('/api/v1/driver/me')->assertOk()->json('data.driver');
            $this->assertSame(__('admin.drivers.availability_'.$me['availability']['status'], [], $locale), $me['availability']['status_label']);
            $order = $this->as($locale, $token)->getJson('/api/v1/driver/orders/active')->assertOk()->json('data.orders.0');
            $this->assertSame(__('admin.order_status.'.$order['status'], [], $locale), $order['status_label']);
            $this->assertSame(__('admin.orders.type_'.$order['type'], [], $locale), $order['type_label']);
        }
    }
}
