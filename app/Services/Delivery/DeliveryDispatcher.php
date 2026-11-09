<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DeliveryOffer;
use App\Models\Order;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Notifications\AdminAlerts;
use App\Support\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI dispatch B3 (2026-11-10) — automatic reassignment.
 *
 * advance(): after a rejection, an expiry or a withdrawn offer, offers the
 * order to the next driver of the CURRENT round (fair rotation,
 * DriverRecommendationService::dispatchCandidates). When the round's
 * attempt cap is reached or nobody is eligible, the order goes to the
 * admin queue (dispatch_state = no_driver, status stays `accepted`) and
 * admins are alerted ONCE until an admin acts.
 *
 * recover(): retries queued orders, oldest first (fair among waiting
 * orders; a driver can only hold one pending offer, so the oldest order
 * claims a newly free driver first). Explicit rules:
 *  1. same round: while the round has attempts left, offer to an eligible
 *     driver NOT yet offered in this round (e.g. a driver who just became
 *     available); rejecting/expired drivers are never retried in the round;
 *  2. new round: only when no one is left in the round, the order has
 *     waited at least `dispatch.recovery_round_cooldown_minutes` in the
 *     queue, and fewer than `dispatch.max_auto_rounds` rounds were started
 *     automatically since the last admin-started round. A new round may
 *     offer previously rejecting drivers again (after the cool-down);
 *  3. otherwise the order waits for an admin (B4 restart / direct assign).
 *
 * Every entry point is idempotent and starts by locking the order row, so
 * duplicate jobs, the sweep and API calls can run at the same time: at
 * most one pending offer exists per order (also enforced by the database).
 * Nothing runs while config('dispatch.offers_enabled') is off. No method
 * calls itself: one call creates at most one offer.
 */
class DeliveryDispatcher
{
    /** Competing writers can invalidate a chosen driver; try the next one a few times. */
    protected const PICK_RETRIES = 3;

    public function __construct(
        protected DeliveryOfferService $offers,
        protected DriverRecommendationService $recommendations,
    ) {}

    /**
     * @return string offered | pending | queued | not_dispatching | disabled | contended
     */
    public function advance(int $orderId): string
    {
        if (! DeliveryOfferService::enabled()) {
            return 'disabled';
        }

        return $this->withRetries(fn () => DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $order || $order->status !== OrderStatus::Accepted || $order->dispatch_state === null) {
                return 'not_dispatching';
            }
            if ($this->hasPendingOffer($order)) {
                return 'pending';
            }
            if ($order->dispatch_state === Order::DISPATCH_NO_DRIVER) {
                return 'queued'; // recovery decides
            }
            if ($order->dispatch_attempts >= DeliveryOfferService::maxAttemptsPerRound()) {
                return $this->queue($order);
            }

            $candidate = $this->recommendations->dispatchCandidates($order, $order->dispatch_round, 1)->first();
            if (! $candidate) {
                return $this->queue($order);
            }
            $this->offers->offerAutomatically($order, $candidate['driver'], $candidate['meta']);

            return 'offered';
        }));
    }

    /**
     * Retries orders waiting in the no-driver queue, oldest first.
     *
     * @return array{resumed: int, new_round: int, waiting: int}
     */
    public function recover(int $limit = 50): array
    {
        $counts = ['resumed' => 0, 'new_round' => 0, 'waiting' => 0];
        if (! DeliveryOfferService::enabled() || ! (bool) PlatformSettings::get('dispatch.auto_recovery')) {
            return $counts;
        }

        $ids = Order::query()
            ->where('status', OrderStatus::Accepted->value)
            ->where('dispatch_state', Order::DISPATCH_NO_DRIVER)
            ->orderBy('no_driver_since')->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $outcome = $this->recoverOne((int) $id);
            if (isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /** @return string resumed | new_round | waiting | skipped | contended */
    public function recoverOne(int $orderId): string
    {
        return $this->withRetries(fn () => DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $order || $order->status !== OrderStatus::Accepted || $order->dispatch_state !== Order::DISPATCH_NO_DRIVER || $this->hasPendingOffer($order)) {
                return 'skipped';
            }

            // Rule 1: same round, a driver not yet offered in it.
            if ($order->dispatch_attempts < DeliveryOfferService::maxAttemptsPerRound()) {
                $candidate = $this->recommendations->dispatchCandidates($order, $order->dispatch_round, 1)->first();
                if ($candidate) {
                    $this->offers->offerAutomatically($order, $candidate['driver'], $candidate['meta'] + ['recovery' => 'same_round']);

                    return 'resumed';
                }
            }

            // Rule 2: a new automatic round, after the cool-down and within the cap.
            if ($this->mayStartAutomaticRound($order)) {
                $candidate = $this->recommendations->dispatchCandidates($order, $order->dispatch_round + 1, 1)->first();
                if ($candidate) {
                    $this->offers->offerAutomatically($order, $candidate['driver'], $candidate['meta'] + ['recovery' => 'new_round'], true);

                    return 'new_round';
                }
            }

            return 'waiting'; // Rule 3: an admin decides.
        }));
    }

    public static function recoveryCooldownMinutes(): int
    {
        return max(1, (int) PlatformSettings::get('dispatch.recovery_round_cooldown_minutes'));
    }

    public static function maxAutomaticRounds(): int
    {
        return max(0, min(10, (int) PlatformSettings::get('dispatch.max_auto_rounds')));
    }

    protected function mayStartAutomaticRound(Order $order): bool
    {
        if ($order->no_driver_since === null || $order->no_driver_since->greaterThan(now()->subMinutes(self::recoveryCooldownMinutes()))) {
            return false;
        }
        $lastAdminRound = (int) DeliveryOffer::query()->where('order_id', $order->id)
            ->where('source', DeliveryOffer::SOURCE_ADMIN)->max('round');

        return ($order->dispatch_round - $lastAdminRound) < self::maxAutomaticRounds();
    }

    /** Into the admin queue; alerts admins only when the order newly enters it and was not announced yet. */
    protected function queue(Order $order): string
    {
        if ($this->offers->markNoDriver($order) && $order->no_driver_alerted_at === null) {
            Order::query()->whereKey($order->id)->update(['no_driver_alerted_at' => now()]);
            AdminAlerts::send('order.no_driver_available', (int) $order->id, (string) $order->order_number);
        }

        return 'queued';
    }

    protected function hasPendingOffer(Order $order): bool
    {
        return DeliveryOffer::query()->where('pending_order_marker', $order->id)->exists();
    }

    /** Re-runs $attempt when a chosen driver was taken or became ineligible meanwhile. */
    protected function withRetries(callable $attempt): string
    {
        for ($i = 0; $i < self::PICK_RETRIES; $i++) {
            try {
                return $attempt();
            } catch (BusinessRuleException $e) {
                if (! in_array($e->getStatus(), [409, 422], true)) {
                    throw $e;
                }
            }
        }

        return 'contended';
    }
}
