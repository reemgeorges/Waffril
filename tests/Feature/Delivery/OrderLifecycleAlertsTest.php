<?php

namespace Tests\Feature\Delivery;

use App\Events\Orders\OrderPlaced;
use App\Models\Admin;
use App\Models\City;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Order\OrderAdminService;
use App\Support\Broadcasting\RecordingBroadcaster;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-17) — order lifecycle events alert the right admins in
 * the Filament bell (stored in Filament's format, unread until handled, with
 * a live toast), after the commit and exactly once per event. Driven through
 * the real driver API where a driver acts.
 */
class OrderLifecycleAlertsTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    private Admin $dispatcher;   // orders.view + orders.assign

    private Admin $viewer;       // orders.view only

    private Admin $outsider;     // no order permissions

    private Admin $actor;        // super admin who performs admin actions

    private City $cityModel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dispatch.offers_enabled' => true]);
        RecordingBroadcaster::reset();
        $this->dispatcher = $this->employee(['orders.view', 'orders.assign']);
        $this->viewer = $this->employee(['orders.view']);
        $this->outsider = $this->employee(['plus.view']);
        $this->actor = $this->superAdmin();
        $this->cityModel = $this->city();
    }

    private function acceptedOrder(): Order
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->cityModel), '10', ['cash' => '10']);

        return app(OrderAdminService::class)->accept($order, $this->actor)->fresh();
    }

    private function driverToken(Driver $driver): string
    {
        $driver->forceFill(['password' => 'test-only-password-1'])->save();

        return $this->postJson('/api/v1/driver/login', ['phone' => $driver->phone, 'password' => 'test-only-password-1'])->assertOk()->json('data.token');
    }

    private function asDriver(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function adminAlertCount(): int
    {
        return DatabaseNotification::query()->where('notifiable_type', (new Admin)->getMorphClass())->count();
    }

    /** Alerts of one type stored for an admin. */
    private function alerts(Admin $admin, string $type): array
    {
        return DatabaseNotification::query()->where('notifiable_type', $admin->getMorphClass())->where('notifiable_id', $admin->id)
            ->get()->filter(fn ($n) => ($n->data['viewData']['waffrli_alert'] ?? null) === $type)->values()->all();
    }

    public function test_driver_acceptance_alerts_dispatchers_with_a_link_and_a_toast_once(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->driver($this->cityModel, ['name' => 'Samer Haddad']);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->actor);
        $token = $this->driverToken($driver);

        $this->asDriver($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk();
        // The driver's app retries the same request: idempotent, no second alert.
        $this->asDriver($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk();

        $this->assertSame('assigned', $order->fresh()->status->value);
        $alerts = $this->alerts($this->dispatcher, 'order.driver_accepted');
        $this->assertCount(1, $alerts);
        $alert = $alerts[0];
        $this->assertNull($alert->read_at, 'Unread until handled.');
        $this->assertSame('filament', $alert->data['format']);
        $this->assertStringContainsString($order->order_number, $alert->data['body']);
        $this->assertStringContainsString('Samer Haddad', $alert->data['body']);
        $this->assertStringEndsWith('/admin/orders/'.$order->id, $alert->data['actions'][0]['url']);
        $this->assertSame([], $this->alerts($this->viewer, 'order.driver_accepted'), 'Only admins who can assign drivers.');
        $this->assertSame([], $this->alerts($this->outsider, 'order.driver_accepted'));

        // Live: bell refresh event and a toast on the dispatcher's private channel.
        $this->assertNotEmpty(RecordingBroadcaster::on("private-admin.{$this->dispatcher->id}", 'database-notifications.sent'));
        $toast = collect(RecordingBroadcaster::on("private-admin.{$this->dispatcher->id}", 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'))
            ->firstWhere('payload.id', 'waffrli-alert-order-driver_accepted-'.$order->id);
        $this->assertNotNull($toast);
    }

    public function test_declined_and_expired_offers_alert_dispatchers(): void
    {
        $order = $this->acceptedOrder();
        $first = $this->driver($this->cityModel, ['name' => 'Rami One']);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $first, $this->actor);
        $token = $this->driverToken($first);

        $this->asDriver($token)->postJson("/api/v1/driver/offers/{$offer->id}/reject", ['reason' => 'too_far'])->assertOk();
        $this->asDriver($token)->postJson("/api/v1/driver/offers/{$offer->id}/reject", ['reason' => 'too_far'])->assertOk();
        $this->assertCount(1, $this->alerts($this->dispatcher, 'order.offer_rejected'));
        $this->assertStringContainsString('Rami One', $this->alerts($this->dispatcher, 'order.offer_rejected')[0]->data['body']);

        $second = $this->driver($this->cityModel, ['name' => 'Ziad Two']);
        $next = DeliveryOffer::query()->where('order_id', $order->id)->where('status', 'pending')->first()
            ?? app(DeliveryOfferService::class)->offerByAdmin($order->fresh(), $second, $this->actor);
        $this->travelTo($next->expires_at->copy()->addSecond());
        app(DeliveryOfferService::class)->expire($next);
        app(DeliveryOfferService::class)->expire($next);   // the expiry job runs twice
        $this->assertCount(1, $this->alerts($this->dispatcher, 'order.offer_expired'));
        $this->assertSame([], $this->alerts($this->viewer, 'order.offer_expired'));
    }

    public function test_pickup_and_delivery_alert_order_followers_but_gps_pings_do_not(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->driver($this->cityModel, ['name' => 'Fadi Three']);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->actor);
        $token = $this->driverToken($driver);
        $this->asDriver($token)->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk();

        $before = DatabaseNotification::query()->count();
        $this->asDriver($token)->postJson('/api/v1/driver/location', ['lat' => 33.5101, 'lng' => 36.2911])->assertOk();
        $this->asDriver($token)->postJson('/api/v1/driver/location', ['lat' => 33.5102, 'lng' => 36.2912])->assertOk();
        $this->assertSame($before, DatabaseNotification::query()->count(), 'GPS pings never alert.');

        $this->asDriver($token)->postJson("/api/v1/driver/orders/{$order->id}/pickup")->assertOk();
        $this->asDriver($token)->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['cash_collected' => '10'])->assertOk();

        foreach ([$this->viewer, $this->dispatcher] as $admin) {
            $this->assertCount(1, $this->alerts($admin, 'order.picked_up'));
            $this->assertCount(1, $this->alerts($admin, 'order.delivered'));
        }
        $this->assertSame([], $this->alerts($this->outsider, 'order.delivered'));
    }

    public function test_customer_cancellation_alerts_and_admin_cancellation_does_not(): void
    {
        $branch = $this->branch($this->store(), $this->cityModel);
        $pending = $this->order($this->customer(), $branch, '10');
        app(OrderAdminService::class)->cancelByCustomer($pending);
        $this->assertCount(1, $this->alerts($this->viewer, 'order.cancelled_by_customer'));

        $other = $this->acceptedOrder();
        $before = $this->adminAlertCount();
        app(OrderAdminService::class)->cancelByAdmin($other, $this->actor, ['reason_code' => 'other']);
        // (The customer still gets their own "order cancelled" notification.)
        $this->assertSame($before, $this->adminAlertCount(), 'An admin cancellation alerts no admin.');
    }

    public function test_inactive_admins_and_muted_seeding_get_nothing_and_new_orders_still_alert(): void
    {
        $this->dispatcher->forceFill(['is_active' => false])->save();
        $order = $this->acceptedOrder();
        $driver = $this->driver($this->cityModel);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->actor);
        $this->asDriver($this->driverToken($driver))->postJson("/api/v1/driver/offers/{$offer->id}/accept")->assertOk();
        $this->assertSame([], $this->alerts($this->dispatcher, 'order.driver_accepted'));

        $this->assertCount(1, $this->alerts($this->actor, 'order.driver_accepted'), 'Active admins still get it.');

        // Demo seeding mutes notifications: nothing new.
        config(['waffrli.notifications_muted' => true]);
        $muted = $this->acceptedOrder();
        $quiet = $this->driver($this->cityModel);
        $mutedOffer = app(DeliveryOfferService::class)->offerByAdmin($muted, $quiet, $this->actor);
        app(DeliveryOfferService::class)->accept($mutedOffer, $quiet);
        $this->assertCount(1, $this->alerts($this->actor, 'order.driver_accepted'));

        // A new customer order still alerts (existing behaviour).
        config(['waffrli.notifications_muted' => false]);
        event(new OrderPlaced((int) $muted->id));
        $this->assertCount(1, $this->alerts($this->viewer, 'order.placed'));
    }

    public function test_a_rolled_back_change_sends_no_alert_and_does_not_burn_its_key(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->driver($this->cityModel);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->actor);

        try {
            DB::transaction(function () use ($offer, $driver) {
                app(DeliveryOfferService::class)->accept($offer, $driver);
                throw new \RuntimeException('something later failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('accepted', $order->fresh()->status->value);
        $this->assertSame([], $this->alerts($this->dispatcher, 'order.driver_accepted'));

        // The real acceptance afterwards alerts normally.
        app(DeliveryOfferService::class)->accept($offer->fresh(), $driver);
        $this->assertCount(1, $this->alerts($this->dispatcher, 'order.driver_accepted'));
    }
}
