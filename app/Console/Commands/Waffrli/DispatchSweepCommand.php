<?php

namespace App\Console\Commands\Waffrli;

use App\Enums\OrderStatus;
use App\Models\DeliveryOffer;
use App\Models\Order;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryOfferService;
use Illuminate\Console\Command;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — the safety net of delivery offers,
 * scheduled every 15 seconds (routes/console.php):
 *
 *  1. expires pending offers past their deadline whose ExpireDeliveryOffer
 *     job was lost (worker down, queue cleared) and moves those orders on;
 *  2. moves on orders still "offering" without a pending offer (an
 *     AdvanceDeliveryDispatch job that never ran);
 *  3. retries orders waiting in the no-driver queue (auto-recovery).
 *
 * Bounded per run (config dispatch.sweep_batch), idempotent, and a no-op
 * while delivery offers are disabled. It never cancels orders.
 */
class DispatchSweepCommand extends Command
{
    protected $signature = 'waffrli:dispatch-sweep';

    protected $description = 'Expire overdue delivery offers, move stalled dispatches on and retry orders waiting for a driver';

    public function handle(DeliveryOfferService $offers, DeliveryDispatcher $dispatcher): int
    {
        if (! DeliveryOfferService::enabled()) {
            $this->info('Delivery offers are disabled: nothing to do.');

            return self::SUCCESS;
        }
        $batch = max(1, (int) config('dispatch.sweep_batch', 50));

        $expired = 0;
        $overdue = DeliveryOffer::query()->pending()->where('expires_at', '<=', now())
            ->orderBy('expires_at')->limit($batch)->get();
        foreach ($overdue as $offer) {
            $expired += $offers->expire($offer) ? 1 : 0;
            $dispatcher->advance((int) $offer->order_id);
        }

        $advanced = 0;
        $stalled = Order::query()
            ->where('status', OrderStatus::Accepted->value)
            ->where('dispatch_state', Order::DISPATCH_OFFERING)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('delivery_offers')->whereColumn('delivery_offers.pending_order_marker', 'orders.id'))
            ->orderBy('updated_at')->limit($batch)->pluck('id');
        foreach ($stalled as $id) {
            $advanced += $dispatcher->advance((int) $id) === 'offered' ? 1 : 0;
        }

        $recovered = $dispatcher->recover($batch);

        $this->info(sprintf(
            'Expired %d, advanced %d, recovered %d (+%d new rounds), still waiting %d.',
            $expired, $advanced, $recovered['resumed'], $recovered['new_round'], $recovered['waiting'],
        ));

        return self::SUCCESS;
    }
}
