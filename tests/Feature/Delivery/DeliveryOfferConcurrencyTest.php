<?php

namespace Tests\Feature\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Jobs\Delivery\AdvanceDeliveryDispatch;
use App\Jobs\Delivery\ExpireDeliveryOffer;
use App\Models\Admin;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Order\OrderAdminService;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\WaffrliFixtures;
use Tests\TestCase;

/**
 * WAFFRLI dispatch B2 (2026-11-10) — REAL concurrency on MySQL: several
 * PHP processes act on the same offer at the same instant (row locks,
 * conditional updates and unique markers must leave exactly one outcome).
 *
 * The competitors are separate processes, so the data is committed
 * (DatabaseMigrations, not a wrapping transaction). Runs only against the
 * ISOLATED waffrli_test database; NOT RUN (skipped) anywhere else:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=waffrli_test php artisan test --group mysql
 */
#[Group('mysql')]
class DeliveryOfferConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as migrateIsolatedDatabase;
    }
    use WaffrliFixtures;

    /** Migrates only the isolated MySQL database; elsewhere the test is skipped untouched. */
    public function runDatabaseMigrations(): void
    {
        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'waffrli_test') {
            $this->migrateIsolatedDatabase();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'waffrli_test') {
            $this->markTestSkipped('NOT RUN: requires the isolated MySQL 8 test database waffrli_test.');
        }
        config(['dispatch.offers_enabled' => true]);
        (new AdminAccessSeeder)->run();
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $admin;
    }

    /** @return array{Order, Driver, DeliveryOffer} */
    protected function pendingOffer(): array
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $order = app(OrderAdminService::class)->accept($this->order($this->customer(), $this->branch($this->store(), $city), '10'), $admin);
        $driver = $this->driver($city);
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $admin);

        return [$order, $driver, $offer];
    }

    /**
     * Starts every action as its own PHP process, all released at the same instant.
     *
     * @param  list<string>  $actions
     * @return list<array<string, mixed>>
     */
    protected function race(array $actions, DeliveryOffer $offer, Driver $driver, float $startAt): array
    {
        $worker = base_path('tests/Support/Concurrency/offer_race_worker.php');
        $processes = [];
        foreach ($actions as $action) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, $action, (string) $offer->id, (string) $driver->id, sprintf('%.6F', $startAt)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $line = trim((string) collect(explode("\n", trim((string) $out)))->last());
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "Worker output was not JSON: {$out} {$err}");
            $this->assertArrayNotHasKey('error', $decoded, 'Unexpected worker failure: '.($decoded['error'] ?? ''));
            $results[] = $decoded;
        }

        return $results;
    }

    protected function assignedHistory(Order $order): int
    {
        return OrderStatusHistory::where('order_id', $order->id)->where('to_status', 'assigned')->count();
    }

    public function test_six_simultaneous_accepts_assign_exactly_once(): void
    {
        [$order, $driver, $offer] = $this->pendingOffer();

        $results = $this->race(array_fill(0, 6, 'accept'), $offer, $driver, microtime(true) + 3);

        // The winner assigns; the others wait on the lock, re-read and get the same idempotent answer.
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
            $this->assertSame('assigned', $result['result']);
        }
        $this->assertSame(1, $this->assignedHistory($order));
        $this->assertSame(DeliveryOfferStatus::Accepted, $offer->fresh()->status);
        $this->assertSame([$driver->id, $offer->id], [(int) $order->fresh()->driver_id, (int) $order->fresh()->assigned_via_offer_id]);
        // Proof that this was a real race: every process was already running before any finished.
        $this->assertLessThan(collect($results)->min('finished'), collect($results)->max('started'), 'The workers did not overlap.');
    }

    public function test_accepts_racing_withdrawals_leave_one_consistent_outcome(): void
    {
        [$order, $driver, $offer] = $this->pendingOffer();

        $results = $this->race(['accept', 'withdraw', 'accept', 'withdraw', 'accept', 'withdraw'], $offer, $driver, microtime(true) + 3);

        $offer->refresh();
        $order->refresh();
        if ($offer->status === DeliveryOfferStatus::Accepted) {
            $this->assertSame(OrderStatus::Assigned, $order->status);
            $this->assertSame($driver->id, (int) $order->driver_id);
            $this->assertSame(1, $this->assignedHistory($order));
            $this->assertTrue(collect($results)->where('action', 'withdraw')->every(fn ($r) => $r['ok'] && $r['result'] === 'noop'), 'Nothing left to withdraw after acceptance.');
        } else {
            $this->assertSame(DeliveryOfferStatus::Cancelled, $offer->status);
            $this->assertSame(OrderStatus::Accepted, $order->status);
            $this->assertNull($order->driver_id);
            $this->assertNull($order->dispatch_state);
            $this->assertSame(0, $this->assignedHistory($order));
            $this->assertTrue(collect($results)->where('action', 'accept')->every(fn ($r) => ! $r['ok'] && $r['status'] === 409), 'A withdrawn offer is never accepted.');
        }
        // Exactly one withdrawal succeeded if and only if the offer ended up withdrawn.
        $successfulWithdrawals = collect($results)->where('action', 'withdraw')->where('result', 'cancelled')->count();
        $this->assertSame($offer->status === DeliveryOfferStatus::Cancelled ? 1 : 0, $successfulWithdrawals);
    }

    public function test_accepts_racing_the_deadline_never_assign_after_expiry(): void
    {
        [$order, $driver, $offer] = $this->pendingOffer();
        // Move the deadline close and release everyone exactly at it.
        $deadline = now()->addSeconds(3)->startOfSecond();
        DB::table('delivery_offers')->where('id', $offer->id)->update(['expires_at' => $deadline]);

        $results = $this->race(['accept', 'expire', 'accept', 'expire', 'accept', 'expire'], $offer, $driver, (float) $deadline->getTimestamp());

        $offer->refresh();
        $order->refresh();
        $this->assertContains($offer->status, [DeliveryOfferStatus::Accepted, DeliveryOfferStatus::Expired]);
        if ($offer->status === DeliveryOfferStatus::Accepted) {
            $this->assertTrue($offer->responded_at->lessThan($deadline), 'Accepted only before the deadline.');
            $this->assertSame(1, $this->assignedHistory($order));
        } else {
            $this->assertNull($order->driver_id);
            $this->assertSame(OrderStatus::Accepted, $order->status);
            $this->assertSame(0, $this->assignedHistory($order));
        }
        $this->assertLessThanOrEqual(1, collect($results)->where('action', 'expire')->where('result', 'expired')->count(), 'Expired at most once.');
    }

    public function test_six_simultaneous_dispatch_steps_create_exactly_one_next_offer(): void
    {
        [$order, $driver, $offer] = $this->pendingOffer();
        $this->driver($order->city);
        $this->driver($order->city);
        // Reject without the automatic follow-up, so the competitors race for it.
        Bus::fake([AdvanceDeliveryDispatch::class, ExpireDeliveryOffer::class]);
        app(DeliveryOfferService::class)->reject($offer, $driver, 'busy');

        $results = $this->race(array_fill(0, 6, 'advance'), $offer, $driver, microtime(true) + 3);

        $this->assertSame(1, DeliveryOffer::query()->pending()->where('order_id', $order->id)->count(), 'Exactly one next offer.');
        $this->assertSame(2, DeliveryOffer::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, collect($results)->where('result', 'offered')->count(), json_encode($results));
        $this->assertTrue(collect($results)->every(fn ($r) => $r['ok'] && in_array($r['result'], ['offered', 'pending'], true)), json_encode($results));
        $this->assertLessThan(collect($results)->min('finished'), collect($results)->max('started'), 'The workers did not overlap.');
    }

    public function test_the_expiry_job_racing_acceptance_at_the_deadline_never_double_assigns(): void
    {
        [$order, $driver, $offer] = $this->pendingOffer();
        $next = $this->driver($order->city);
        $deadline = now()->addSeconds(3)->startOfSecond();
        DB::table('delivery_offers')->where('id', $offer->id)->update(['expires_at' => $deadline]);

        $this->race(['accept', 'expire_job', 'accept', 'expire_job', 'accept', 'expire_job'], $offer, $driver, (float) $deadline->getTimestamp());

        $offer->refresh();
        $order->refresh();
        if ($offer->status === DeliveryOfferStatus::Accepted) {
            $this->assertSame([OrderStatus::Assigned, $driver->id], [$order->status, (int) $order->driver_id]);
            $this->assertSame(1, DeliveryOffer::query()->where('order_id', $order->id)->count(), 'No next offer after acceptance.');
        } else {
            $this->assertSame(DeliveryOfferStatus::Expired, $offer->status);
            $this->assertSame(OrderStatus::Accepted, $order->status);
            $pending = DeliveryOffer::query()->pending()->where('order_id', $order->id)->get();
            $this->assertCount(1, $pending, 'Exactly one next offer after expiry.');
            $this->assertSame($next->id, (int) $pending->first()->driver_id);
        }
        $this->assertLessThanOrEqual(1, $this->assignedHistory($order));
    }
}
