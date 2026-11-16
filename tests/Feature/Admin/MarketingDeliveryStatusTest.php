<?php

namespace Tests\Feature\Admin;

use App\Contracts\Push\PushTransport;
use App\Jobs\Push\SendMarketingBroadcast;
use App\Models\Admin;
use App\Models\MarketingBroadcast;
use App\Models\User;
use App\Services\Notifications\MarketingBroadcastService;
use App\Services\Push\DeviceTokenService;
use App\Services\Push\FakePushTransport;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * WAFFRLI (2026-11-16) — a marketing message reports each delivery stage
 * honestly: recipients processed, in-app notifications saved, phone pushes
 * queued, accepted by Firebase, invalid, skipped, failed, still waiting, and
 * recipients without a device. "Sent" is shown as "Processed"; nothing
 * claims that a phone displayed the message. Covers QUEUE_CONNECTION=sync
 * and the database queue (worker stopped, then started), and at-most-once.
 */
class MarketingDeliveryStatusTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakePushTransport::reset();
        config(['waffrli.push.driver' => 'fake', 'waffrli.push.skip_when_online' => false]);
    }

    private function send(): MarketingBroadcast
    {
        $admin = Admin::factory()->create(['is_active' => true]);
        $admin->givePermissionTo('notifications.marketing');

        return app(MarketingBroadcastService::class)->create($admin, [
            'title' => ['ar' => 'عرض', 'en' => 'Offer'], 'body' => ['ar' => 'نص', 'en' => 'Text'],
        ])->fresh();
    }

    /** Two customers with a working phone, one with an unregistered token. */
    private function devices(): array
    {
        $service = app(DeviceTokenService::class);
        $ok1 = $service->register($this->demoCustomer(1), ['token' => 'fcm-ok-'.str_repeat('a', 30), 'platform' => 'android', 'device_id' => 'd1']);
        $ok2 = $service->register($this->demoCustomer(2), ['token' => 'fcm-ok-'.str_repeat('b', 30), 'platform' => 'ios', 'device_id' => 'd2']);
        $dead = $service->register($this->demoCustomer(3), ['token' => FakePushTransport::UNREGISTERED_PREFIX.str_repeat('c', 24), 'platform' => 'android', 'device_id' => 'd3']);

        return [$ok1, $ok2, $dead];
    }

    /**
     * Runs the worker one job at a time until the queue is empty. (A daemon
     * worker, --stop-when-empty, checks its memory limit before taking a job
     * and can stop at once inside a long PHPUnit process; --once does not.)
     */
    private function workUntilEmpty(): void
    {
        for ($i = 0; $i < 50 && DB::table('jobs')->count() > 0; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--queue' => config('waffrli.push.queue', 'default')]);
        }
    }

    private function stored(MarketingBroadcast $b): int
    {
        return DatabaseNotification::query()->where('type', 'marketing.message')->where('data->marketing_broadcast_id', $b->id)->count();
    }

    public function test_sync_queue_records_every_stage_without_claiming_device_delivery(): void
    {
        config(['queue.default' => 'sync', 'waffrli.push.enabled' => true]);
        $this->devices();

        $b = $this->send();
        $recipients = User::query()->whereNull('anonymized_at')->where('is_active', true)->where('account_status', '!=', User::ACCOUNT_SUSPENDED)->count();

        $this->assertSame(MarketingBroadcast::STATUS_SENT, $b->status);
        $this->assertSame($recipients, $b->recipients_count);
        $this->assertSame($recipients, $b->stored_count);
        $this->assertSame($this->stored($b), $b->stored_count, 'Counted from the notifications table.');
        $this->assertTrue($b->push_enabled);
        $this->assertSame(3, $b->push_queued_count);
        $this->assertSame(2, $b->push_accepted_count);
        $this->assertSame(1, $b->push_invalid_count);
        $this->assertSame(0, $b->pushPending());
        $this->assertSame($recipients - 3, $b->push_no_device_count);
        $this->assertCount(2, FakePushTransport::$sent);

        $lines = implode("\n", $b->deliveryLines());
        $this->assertStringContainsString(__('admin.marketing.push_accepted', ['count' => 2]), $lines);
        $this->assertStringNotContainsString('delivered', strtolower($lines));
        $this->assertSame('Processed', __('admin.marketing.status_sent', [], 'en'));
    }

    public function test_push_disabled_is_reported_as_off_and_nothing_is_queued(): void
    {
        config(['queue.default' => 'sync', 'waffrli.push.enabled' => false]);
        $this->devices();

        $b = $this->send();

        $this->assertFalse($b->push_enabled);
        $this->assertSame(0, $b->push_queued_count);
        $this->assertSame([], FakePushTransport::$sent);
        $this->assertContains(__('admin.marketing.push_off'), $b->deliveryLines());
        $this->assertGreaterThan(0, $b->stored_count);
    }

    public function test_database_queue_with_the_worker_stopped_then_started_and_at_most_once(): void
    {
        config(['queue.default' => 'database', 'waffrli.push.enabled' => true]);
        $this->devices();

        $b = $this->send();
        // Worker stopped: nothing happens, and the panel says why.
        $this->assertSame(MarketingBroadcast::STATUS_QUEUED, $b->status);
        $this->assertSame(0, $this->stored($b));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->travel(3)->minutes();
        $this->assertTrue($b->fresh()->waitingForWorker());
        $this->assertSame([__('admin.marketing.waiting_worker')], $b->fresh()->deliveryLines());

        // A duplicate dispatch of the same message is queued too (e.g. a retry by hand).
        SendMarketingBroadcast::dispatch($b->id);

        // Worker processes the broadcast jobs only (pushes stay queued meanwhile).
        Artisan::call('queue:work', ['--once' => true, '--queue' => config('waffrli.push.queue', 'default')]);
        $b->refresh();
        $this->assertSame(MarketingBroadcast::STATUS_SENT, $b->status);
        $this->assertSame(3, $b->push_queued_count);
        $this->assertSame(3, $b->pushPending(), 'Pushes wait for the worker.');
        $this->assertStringContainsString(__('admin.marketing.push_pending', ['count' => 3]), implode("\n", $b->deliveryLines()));

        $this->workUntilEmpty();
        $b->refresh();
        $this->assertSame(0, $b->pushPending());
        $this->assertSame(2, $b->push_accepted_count);
        $this->assertSame(1, $b->push_invalid_count);
        // At most once: the duplicate job claimed nothing; every recipient got exactly one notification.
        $this->assertSame($b->recipients_count, $this->stored($b));
        $this->assertCount(2, FakePushTransport::$sent);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_push_that_fails_after_its_retries_is_counted_as_failed(): void
    {
        config(['queue.default' => 'sync', 'waffrli.push.enabled' => true]);
        app(DeviceTokenService::class)->register($this->demoCustomer(1), ['token' => 'fcm-down-'.str_repeat('z', 30), 'platform' => 'android', 'device_id' => 'dz']);
        $this->app->instance(PushTransport::class, new class implements PushTransport
        {
            public function send(string $token, array $message): string
            {
                throw new RuntimeException('FCM unavailable');
            }

            public function name(): string
            {
                return 'down';
            }
        });

        $b = $this->send();

        $this->assertSame(1, $b->push_queued_count);
        $this->assertSame(1, $b->push_failed_count);
        $this->assertSame(0, $b->push_accepted_count);
        $this->assertSame(0, $b->pushPending());
        $this->assertSame(MarketingBroadcast::STATUS_SENT, $b->status, 'A push failure never stops the in-app delivery.');
    }
}
