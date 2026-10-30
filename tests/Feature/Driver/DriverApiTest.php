<?php

namespace Tests\Feature\Driver;

use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Driver\DriverDeliveryService;
use App\Services\Order\OrderAdminService;
use App\Support\Money\Money;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Database\Seeders\WaffrliDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WAFFRLI driver API (2026-11-02) — every driver endpoint against the
 * seeded demo data (MySQL 8 and SQLite).
 *
 * Demo drivers: 1 (DEMO-0031, assigned), 2 (DEMO-0032, assigned, USD with
 * free delivery), 3 (DEMO-0033, picked up), 4 (DEMO-0034, picked up),
 * 5 and 8 idle, 6 offline, 7 (DEMO-0035, assigned, SYP).
 */
class DriverApiTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'test-only-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(WaffrliDemoSeeder::class);
    }

    protected function driver(int $n): Driver
    {
        $driver = DemoPeopleSeeder::driver($n);
        $driver->forceFill(['password' => self::PASSWORD])->save();

        return $driver->fresh();
    }

    protected function login(int $n): string
    {
        $driver = $this->driver($n);

        return $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => self::PASSWORD])
            ->assertOk()->json('data.token');
    }

    protected function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    protected function order(string $number): Order
    {
        return Order::query()->where('order_number', $number)->firstOrFail();
    }

    /* ---------------- Authentication ---------------- */

    public function test_login_returns_a_driver_token_and_never_credentials(): void
    {
        $driver = $this->driver(1);

        $response = $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => self::PASSWORD, 'device_name' => 'Pixel test'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.driver.id', $driver->id)
            ->assertJsonPath('data.driver.availability.has_active_delivery', true);

        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $this->assertSame(['driver', '*'], $driver->tokens()->first()->abilities);
        $this->assertSame('Pixel test', $driver->tokens()->first()->name);
    }

    public function test_invalid_credentials_inactive_driver_and_validation(): void
    {
        $driver = $this->driver(1);

        $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'wrong-password'])
            ->assertStatus(401)->assertJsonPath('status', false)->assertJsonPath('error', __('messages.driver.invalid_credentials'));
        $this->postJson('/api/v1/driver/login', ['phone' => '+12025559999', 'password' => self::PASSWORD])
            ->assertStatus(401)->assertJsonPath('error', __('messages.driver.invalid_credentials'));
        $this->postJson('/api/v1/driver/login', ['phone' => 'not-a-phone', 'password' => 'x'])->assertStatus(400);

        $driver->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('error', __('messages.driver.account_inactive'));
    }

    public function test_login_is_throttled(): void
    {
        RateLimiter::clear('driver-login');
        $driver = $this->driver(5);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'wrong-password'])->assertStatus(401);
        }
        $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_guard_isolation_and_token_lifecycle(): void
    {
        $this->getJson('/api/v1/driver/me')->assertStatus(401);

        $customer = User::query()->where('email', 'like', '%demo.waffrli.test')->firstOrFail();
        $customerToken = $customer->createToken('t', ['user', '*'])->plainTextToken;
        $this->api($customerToken)->getJson('/api/v1/driver/me')->assertStatus(401);

        $admin = Admin::factory()->create();
        $adminToken = $admin->createToken('t', ['admin', '*'])->plainTextToken;
        $this->api($adminToken)->getJson('/api/v1/driver/orders/active')->assertStatus(401);

        $token = $this->login(1);
        $this->api($token)->getJson('/api/v1/driver/me')->assertOk();
        $this->api($token)->getJson('/api/v1/admin/users/all')->assertStatus(401);

        // Deactivation (as Filament does) blocks protected operations at once.
        Driver::query()->whereKey(DemoPeopleSeeder::driver(1)->id)->update(['is_active' => false]);
        $this->api($token)->getJson('/api/v1/driver/me')->assertStatus(403);
        Driver::query()->whereKey(DemoPeopleSeeder::driver(1)->id)->update(['is_active' => true]);

        $this->api($token)->postJson('/api/v1/driver/logout')->assertOk();
        $this->api($token)->getJson('/api/v1/driver/me')->assertStatus(401);
    }

    /* ---------------- Profile & password ---------------- */

    public function test_profile_only_language_is_editable(): void
    {
        $token = $this->login(1);
        $name = DemoPeopleSeeder::driver(1)->name;

        $this->api($token)->patchJson('/api/v1/driver/profile', ['language' => 'en', 'name' => 'Hacker', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.driver.language', 'en')->assertJsonPath('data.driver.name', $name);
        $this->assertTrue(DemoPeopleSeeder::driver(1)->is_active);
        $this->api($token)->patchJson('/api/v1/driver/profile', ['language' => 'fr'])->assertStatus(400);
    }

    public function test_password_change_revokes_other_sessions(): void
    {
        $first = $this->login(1);
        $second = $this->login(1);

        $this->api($first)->postJson('/api/v1/driver/password', ['current_password' => 'nope-nope-nope', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])
            ->assertStatus(422);
        $this->api($first)->postJson('/api/v1/driver/password', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(400);
        $this->api($first)->postJson('/api/v1/driver/password', ['current_password' => self::PASSWORD, 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])
            ->assertOk();

        $this->api($first)->getJson('/api/v1/driver/me')->assertOk();
        $this->api($second)->getJson('/api/v1/driver/me')->assertStatus(401);
        $this->postJson('/api/v1/driver/login', ['phone' => DemoPeopleSeeder::driver(1)->phone, 'password' => 'brand-new-pass-1'])->assertOk();
    }

    /* ---------------- Availability ---------------- */

    public function test_availability_is_consistent_with_active_deliveries(): void
    {
        $busy = $this->login(1);
        $this->api($busy)->getJson('/api/v1/driver/availability')
            ->assertOk()->assertJsonPath('data.has_active_delivery', true)->assertJsonPath('data.can_go_offline', false)
            ->assertJsonPath('data.active_orders.0.order_number', 'DEMO-0031');
        $this->api($busy)->patchJson('/api/v1/driver/availability', ['availability' => 'offline'])->assertStatus(409);
        $this->assertSame('available', DemoPeopleSeeder::driver(1)->availability);

        $idle = $this->login(5);
        $this->api($idle)->patchJson('/api/v1/driver/availability', ['availability' => 'offline'])->assertOk()->assertJsonPath('data.availability', 'offline');
        $this->api($idle)->patchJson('/api/v1/driver/availability', ['availability' => 'available'])->assertOk()->assertJsonPath('data.availability', 'available');
        $this->api($idle)->patchJson('/api/v1/driver/availability', ['availability' => 'on_break'])->assertStatus(400);
    }

    /* ---------------- Orders ---------------- */

    public function test_drivers_see_only_their_own_orders(): void
    {
        $token = $this->login(1);

        $this->api($token)->getJson('/api/v1/driver/orders/active')
            ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.order_number', 'DEMO-0031')
            ->assertJsonPath('data.orders.0.allowed_actions', ['pickup', 'report_problem']);

        $other = $this->order('DEMO-0032');
        $this->api($token)->getJson("/api/v1/driver/orders/{$other->id}")->assertStatus(404);
        $this->api($token)->postJson("/api/v1/driver/orders/{$other->id}/pickup")->assertStatus(404);
        $this->api($token)->postJson("/api/v1/driver/orders/{$other->id}/deliver")->assertStatus(404);
        $this->api($token)->postJson("/api/v1/driver/orders/{$other->id}/problems", ['type' => 'wrong_address'])->assertStatus(404);
        $this->api($token)->getJson('/api/v1/driver/orders/999999')->assertStatus(404);
        $this->assertSame(OrderStatus::Assigned, $other->fresh()->status);
    }

    public function test_active_order_details_and_history_privacy(): void
    {
        $token = $this->login(1);
        $order = $this->order('DEMO-0031');

        $data = $this->api($token)->getJson("/api/v1/driver/orders/{$order->id}")->assertOk()->json('data.order');
        $this->assertSame($order->user->phone, $data['customer']['phone']);
        $this->assertNotNull($data['delivery']['lat']);
        $this->assertNotEmpty($data['items'][0]['options']);
        $this->assertSame('USD', $data['payment']['currency']['code']);
        $this->assertSame((string) DriverDeliveryService::cashDue($order)->toScale(2), $data['payment']['cash_to_collect']);

        // DEMO-0001 was delivered by driver 1 two weeks ago: no customer PII in history.
        $history = $this->api($token)->getJson('/api/v1/driver/orders/history')->assertOk()->json('data.orders');
        $this->assertContains('DEMO-0001', array_column($history, 'order_number'));
        foreach ($history as $row) {
            $this->assertNull($row['customer']);
            $this->assertNull($row['delivery']['address']);
            $this->assertNull($row['delivery']['lat']);
            $this->assertSame([], $row['allowed_actions']);
        }
        $this->assertNotContains('DEMO-0002', array_column($history, 'order_number'), 'Another driver\'s delivery.');
    }

    /* ---------------- Pickup & delivery ---------------- */

    public function test_pickup_and_delivery_workflow_with_cash_photo_and_duplicates(): void
    {
        $token = $this->login(1);
        $order = $this->order('DEMO-0031');
        $due = (string) DriverDeliveryService::cashDue($order);

        // Invalid transition: deliver before pickup.
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => $due])->assertStatus(422);

        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk()->assertJsonPath('data.order.status', 'picked_up');
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertStatus(422);

        // Wrong amount / currency / missing cash: nothing is collected.
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '1.00'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => $due, 'currency' => 'SYP'])->assertStatus(422);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", [])->assertStatus(422);
        $this->assertSame(0, OrderPayment::where('order_id', $order->id)->where('status', 'paid')->count());

        $photo = UploadedFile::fake()->image('door.jpg', 640, 480);
        $response = $this->api($token)->post("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => $due, 'currency' => 'usd', 'photo' => $photo])
            ->assertOk()->assertJsonPath('data.order.status', 'delivered')->assertJsonPath('data.order.has_delivery_photo', true);
        $this->assertTrue(DriverDeliveryService::cashDue($order->fresh())->isZero(), 'Nothing is left to collect.');

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->delivered_at);
        Storage::disk('local')->assertExists($fresh->delivery_photo_path);
        $cash = OrderPayment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('paid', $cash->status);
        $this->assertSame((string) $cash->amount, (string) $cash->collected_amount);
        $this->assertSame(DemoPeopleSeeder::driver(1)->id, $cash->collected_by_driver_id);
        $this->assertSame(1, DB::table('order_status_histories')->where('order_id', $order->id)->where('to_status', 'delivered')->count());
        $this->assertSame($response->json('data.order.payment.cash_collected'), $response->json('data.order.payment.order_total'));

        // Duplicate completion is refused and changes nothing.
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => $due])
            ->assertStatus(409)->assertJsonPath('error', __('messages.driver.already_delivered'));
        $this->assertSame(1, DB::table('order_status_histories')->where('order_id', $order->id)->where('to_status', 'delivered')->count());
    }

    public function test_cash_is_collected_in_the_order_currency_without_conversion(): void
    {
        $token = $this->login(7);
        $order = $this->order('DEMO-0035');
        $this->assertSame('SYP', $order->currency->code);

        $data = $this->api($token)->getJson("/api/v1/driver/orders/{$order->id}")->json('data.order.payment');
        $this->assertSame('SYP', $data['currency']['code']);
        $this->assertSame(0, $data['currency']['decimals']);
        $this->assertStringNotContainsString('.', $data['cash_to_collect']);

        $before = collect($this->api($token)->getJson('/api/v1/driver/cash/summary')->assertOk()->json('data.currencies'))->keyBy('currency');
        $this->assertSame(Money::toStorage($data['cash_to_collect']), $before['SYP']['to_collect_on_active_deliveries']);

        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => $data['cash_to_collect'], 'currency' => 'SYP'])->assertOk();

        $after = collect($this->api($token)->getJson('/api/v1/driver/cash/summary')->assertOk()->json('data.currencies'))->keyBy('currency');
        $this->assertSame('0.0000', $after['SYP']['to_collect_on_active_deliveries']);
        $this->assertSame(
            Money::toStorage(Money::of($before['SYP']['collected_unsettled'])->plus($data['cash_to_collect'])),
            $after['SYP']['collected_unsettled'],
            'Collected cash moves from "to collect" to "unsettled" exactly, in SYP.'
        );
        $collections = $this->api($token)->getJson('/api/v1/driver/cash/collections')->assertOk()->json('data.collections');
        $this->assertSame('DEMO-0035', $collections[0]['order_number']);
        $this->assertSame('SYP', $collections[0]['currency']);
    }

    public function test_cash_views_and_settlements_are_the_drivers_own(): void
    {
        $token = $this->login(6); // has a disputed and an open settlement in the demo data
        $settlements = $this->api($token)->getJson('/api/v1/driver/cash/settlements')->assertOk()->json('data.settlements');
        $this->assertCount(2, $settlements);
        $this->assertEqualsCanonicalizing(['disputed', 'open'], array_column($settlements, 'status'));

        $collections = $this->api($token)->getJson('/api/v1/driver/cash/collections?per_page=50')->assertOk()->json('data.collections');
        $own = OrderPayment::where('collected_by_driver_id', DemoPeopleSeeder::driver(6)->id)->where('collected_amount', '>', 0)->count();
        $this->assertCount($own, $collections);

        $other = $this->login(5);
        $this->assertCount(1, $this->api($other)->getJson('/api/v1/driver/cash/settlements')->json('data.settlements'));
    }

    /* ---------------- Problems ---------------- */

    public function test_problem_reports_reach_admins_and_never_cancel(): void
    {
        $this->seed(AdminAccessSeeder::class);
        $staff = Admin::factory()->create(['is_active' => true]);
        $staff->assignRole(Admin::SUPER_ADMIN_ROLE);
        $token = $this->login(3);
        $order = $this->order('DEMO-0033');

        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/problems", ['type' => 'other'])->assertStatus(400);
        $this->api($token)->postJson("/api/v1/driver/orders/{$order->id}/problems", ['type' => 'customer_unreachable', 'note' => 'No answer after 3 calls'])
            ->assertOk()->assertJsonPath('data.notified_admins', 1);

        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);
        $this->assertSame(1, $staff->notifications()->where('type', 'driver.problem_reported')->count());
        $this->assertTrue(AdminAuditLog::where('action', 'driver.problem_reported')->where('auditable_id', $order->id)->exists());

        $delivered = $this->order('DEMO-0010'); // delivered by driver 3 earlier
        $this->api($token)->postJson("/api/v1/driver/orders/{$delivered->id}/problems", ['type' => 'order_issue'])->assertStatus(409);
    }

    /* ---------------- Location ---------------- */

    public function test_location_updates_only_during_an_active_delivery(): void
    {
        RateLimiter::clear('driver-location:'.DemoPeopleSeeder::driver(1)->id);
        $token = $this->login(1);

        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.5101, 'lng' => 36.2911])->assertOk()->assertJsonPath('data.location.lat', 33.5101);
        $driver = DemoPeopleSeeder::driver(1);
        $this->assertSame('33.5101000', (string) $driver->last_lat);
        $this->assertTrue($driver->last_location_at->gt(now()->subMinute()));

        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 95, 'lng' => 36.2])->assertStatus(400);
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.5, 'lng' => 200])->assertStatus(400);
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 0, 'lng' => 0])->assertStatus(400);
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 'abc', 'lng' => 36.2])->assertStatus(400);

        // Idle driver: refused; positions of others are untouched.
        $idle = $this->login(5);
        $before = (string) DemoPeopleSeeder::driver(5)->last_lat;
        $this->api($idle)->postJson('/api/v1/driver/location', ['lat' => 33.52, 'lng' => 36.29])
            ->assertStatus(409)->assertJsonPath('error', __('messages.driver.no_active_delivery'));
        $this->assertSame($before, (string) DemoPeopleSeeder::driver(5)->last_lat);

        // After the delivery is cancelled by an admin, tracking stops being accepted.
        $this->seed(AdminAccessSeeder::class);
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);
        app(OrderAdminService::class)->cancelByAdmin($this->order('DEMO-0031'), $admin, ['reason_code' => 'other']);
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.51, 'lng' => 36.29])->assertStatus(409);
    }

    public function test_location_updates_are_rate_limited(): void
    {
        RateLimiter::clear('driver-location:'.DemoPeopleSeeder::driver(2)->id);
        $token = $this->login(2);
        foreach (range(1, 12) as $i) {
            $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.51, 'lng' => 36.29])->assertOk();
        }
        $this->api($token)->postJson('/api/v1/driver/location', ['lat' => 33.51, 'lng' => 36.29])->assertStatus(429);
    }

    /* ---------------- Notifications ---------------- */

    public function test_notifications_belong_to_the_authenticated_driver(): void
    {
        $token = $this->login(1);
        $mine = DemoPeopleSeeder::driver(1)->notifications()->count();
        $this->assertGreaterThan(0, $mine, 'Assignments create stored driver notifications.');

        $list = $this->api($token)->getJson('/api/v1/driver/notifications')->assertOk();
        $this->assertSame($mine, $list->json('data.pagination.total'));
        $ids = array_column($list->json('data.notifications'), 'id');
        $this->assertSame(DemoPeopleSeeder::driver(1)->notifications()->pluck('id')->sort()->values()->all(), collect($ids)->sort()->values()->all());

        $foreign = DemoPeopleSeeder::driver(2)->notifications()->firstOrFail();
        $this->api($token)->postJson("/api/v1/driver/notifications/{$foreign->id}/read")->assertStatus(404);
        $this->assertNull($foreign->fresh()->read_at);

        $this->api($token)->postJson("/api/v1/driver/notifications/{$ids[0]}/read")->assertOk()->assertJsonPath('data.notification.id', $ids[0]);
        $this->api($token)->getJson('/api/v1/driver/notifications?unread=1')->assertOk()->assertJsonPath('data.unread_count', $mine - 1);
        $this->api($token)->postJson('/api/v1/driver/notifications/read', ['ids' => [$foreign->id]])->assertOk()->assertJsonPath('data.marked', 0);
        $this->api($token)->postJson('/api/v1/driver/notifications/read')->assertOk()->assertJsonPath('data.marked', $mine - 1);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame('driver.order_assigned', DemoPeopleSeeder::driver(1)->notifications()->latest()->first()->type);
    }

    public function test_arabic_messages(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/driver/login', ['phone' => DemoPeopleSeeder::driver(1)->phone, 'password' => 'wrong-password'])
            ->assertStatus(401)->assertJsonPath('error', 'رقم الهاتف أو كلمة المرور غير صحيحة.');
    }
}
