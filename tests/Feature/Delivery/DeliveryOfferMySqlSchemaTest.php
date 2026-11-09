<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Models\DeliveryOffer;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Order\OrderAdminService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI dispatch B1 (2026-11-10) — MySQL 8 CHECK constraints on
 * delivery_offers and the orders dispatch columns. Skipped (NOT RUN, never
 * "passed") unless the connection is the isolated MySQL test database:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=waffrli_test php artisan test --group mysql
 */
#[Group('mysql')]
class DeliveryOfferMySqlSchemaTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT RUN: requires an isolated MySQL 8 test database (current driver: '.DB::connection()->getDriverName().').');
        }
        config(['dispatch.offers_enabled' => true]);
    }

    protected function assertRejected(callable $statement, string $label): void
    {
        try {
            $statement();
            $this->fail("Expected the database to reject: {$label}");
        } catch (QueryException $e) {
            // 3819 = check constraint violated, 1062 = duplicate key.
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [3819, 1062], $label);
        }
    }

    public function test_delivery_offer_checks_and_markers(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $order = app(OrderAdminService::class)->accept($this->order($this->customer(), $this->branch($this->store(), $city), '10'), $admin);
        $driver = $this->driver($city);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $admin);

        $update = fn (array $values) => fn () => DB::table('delivery_offers')->where('id', $offer->id)->update($values);
        $this->assertRejected($update(['status' => 'lost']), 'unknown status');
        $this->assertRejected($update(['source' => 'driver']), 'unknown source');
        $this->assertRejected($update(['offered_by_admin_id' => null]), 'admin source without admin');
        $this->assertRejected($update(['expires_at' => $offer->offered_at]), 'empty window');
        $this->assertRejected($update(['status' => 'accepted']), 'final status without responded_at');
        $this->assertRejected($update(['responded_at' => now()]), 'pending with responded_at');
        $this->assertRejected($update(['status' => 'rejected', 'responded_at' => now()]), 'rejected without reason');
        $this->assertRejected($update(['status' => 'rejected', 'responded_at' => now(), 'reject_reason' => 'tired']), 'unknown reject reason');
        $this->assertRejected($update(['reject_reason' => 'busy']), 'reject reason on a pending offer');
        $this->assertRejected($update(['status' => 'cancelled', 'responded_at' => now()]), 'cancelled without reason');
        $this->assertRejected($update(['round' => 0]), 'round 0');

        // Markers follow the status: a final offer frees the order and driver.
        $markers = fn () => DB::table('delivery_offers')->where('id', $offer->id)->first(['pending_order_marker', 'pending_driver_marker']);
        $this->assertSame([(int) $order->id, (int) $driver->id], [(int) $markers()->pending_order_marker, (int) $markers()->pending_driver_marker]);
        DB::table('delivery_offers')->where('id', $offer->id)->update(['status' => 'expired', 'responded_at' => now()]);
        $this->assertSame([null, null], [$markers()->pending_order_marker, $markers()->pending_driver_marker]);
    }

    public function test_orders_dispatch_checks(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $order = app(OrderAdminService::class)->accept($this->order($this->customer(), $this->branch($this->store(), $city), '10'), $admin);

        $update = fn (array $values) => fn () => DB::table('orders')->where('id', $order->id)->update($values);
        $this->assertRejected($update(['dispatch_state' => 'searching']), 'unknown dispatch state');
        $this->assertRejected($update(['dispatch_state' => 'no_driver']), 'no_driver without no_driver_since');
        $this->assertRejected($update(['no_driver_since' => now()]), 'no_driver_since outside the queue');

        DB::table('orders')->where('id', $order->id)->update(['dispatch_state' => 'offering']);
        $this->assertRejected($update(['status' => OrderStatus::Assigned->value]), 'dispatch state on a non-accepted order');

        DB::table('orders')->where('id', $order->id)->update(['dispatch_state' => 'no_driver', 'no_driver_since' => now()]);
        $this->assertSame(Order::DISPATCH_NO_DRIVER, Order::find($order->id)->dispatch_state);
    }

    public function test_assigned_via_offer_foreign_key_restricts_deletion(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $order = app(OrderAdminService::class)->accept($this->order($this->customer(), $this->branch($this->store(), $city), '10'), $admin);
        $driver = $this->driver($city);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $admin);
        app(DeliveryOfferService::class)->accept($offer, $driver);

        try {
            DeliveryOffer::query()->whereKey($offer->id)->delete();
            $this->fail('An offer referenced by its order must not be deletable.');
        } catch (QueryException $e) {
            $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0));
        }
    }
}
