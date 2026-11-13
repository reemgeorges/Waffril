<?php

namespace Tests\Feature\Push;

use App\Jobs\Push\SendPushMessage;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerOrderUpdate;
use App\Services\Realtime\Notify;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Customer\CustomerTestCase;

/**
 * Notification audit (2026-11-13). One channel failing must not cost the
 * recipient the other channels, nor cost other recipients their
 * notification.
 *
 * Reproduced defect: with QUEUE_CONNECTION=sync (no worker, as in a basic
 * local setup) the "broadcast" channel publishes inline. When Reverb is
 * unreachable it throws, Laravel aborts the channel loop, and the push to a
 * phone whose app is closed — the one path that still works without
 * Reverb — was never queued; later recipients got nothing at all.
 */
class NotificationDeliveryResilienceTest extends CustomerTestCase
{
    protected function unreachableReverb(): void
    {
        config([
            'queue.default' => 'sync',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => '1',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
            ],
            'waffrli.push.enabled' => true,
            'waffrli.push.driver' => 'fake',
        ]);
        Broadcast::purge('reverb');
    }

    protected function withDevice(User $user, string $suffix): void
    {
        $this->api($this->loginCustomer($user))->postJson('/api/v1/customer/device-tokens', [
            'token' => 'fcm-test-token-'.str_repeat('x', 20).'-'.$suffix, 'platform' => 'android',
            'device_id' => 'device-'.$suffix, 'device_name' => 'Pixel',
        ])->assertOk();
    }

    public function test_a_realtime_outage_still_stores_and_pushes_for_every_recipient(): void
    {
        $orders = Order::query()->orderBy('id')->get()->unique('user_id')->take(2)->values();
        [$first, $second] = [User::findOrFail($orders[0]->user_id), User::findOrFail($orders[1]->user_id)];
        $this->withDevice($first, 'a');
        $this->withDevice($second, 'b');

        $this->unreachableReverb();
        Bus::fake([SendPushMessage::class]);

        Notify::send($first, new CustomerOrderUpdate('accepted', $orders[0]));
        Notify::send([$first, $second], new CustomerOrderUpdate('delivered', $orders[1]));

        // The in-app list is complete for both customers …
        $this->assertSame(1, $first->notifications()->where('type', 'order.accepted')->count());
        $this->assertSame(1, $first->notifications()->where('type', 'order.delivered')->count());
        $this->assertSame(1, $second->notifications()->where('type', 'order.delivered')->count());

        // … and each phone still gets its push although Reverb is down,
        // pointing at the stored notification (the app marks it read by id).
        Bus::assertDispatchedTimes(SendPushMessage::class, 3);
        $stored = $second->notifications()->where('type', 'order.delivered')->value('id');
        Bus::assertDispatched(SendPushMessage::class, fn (SendPushMessage $job) => $job->message['data']['notification_id'] === $stored
            && $job->dedupeKey === 'notification:'.$stored);
    }

    public function test_each_recipient_gets_its_own_notification_id(): void
    {
        $orders = Order::query()->orderBy('id')->get()->unique('user_id')->take(2)->values();
        [$first, $second] = [User::findOrFail($orders[0]->user_id), User::findOrFail($orders[1]->user_id)];

        Notify::send([$first, $second], new CustomerOrderUpdate('delivered', $orders[0]));

        $a = $first->notifications()->where('type', 'order.delivered')->value('id');
        $b = $second->notifications()->where('type', 'order.delivered')->value('id');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a, $b);
    }
}
