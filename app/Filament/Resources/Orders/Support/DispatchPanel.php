<?php

namespace App\Filament\Resources\Orders\Support;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Models\DeliveryOffer;
use App\Models\Order;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliveryOfferService;
use App\Support\Settings\PlatformSettings;

/**
 * WAFFRLI dispatch B4 (2026-11-10) — what the "Delivery dispatch" panel of
 * the order page shows. Computed on the server for every render (the page
 * polls and also refreshes on admin.orders events); the countdown in the
 * view is informational, the server's expires_at decides.
 */
final class DispatchPanel
{
    /** Panel shown when offers are enabled, or when the order has offer history. */
    public static function visible(Order $order): bool
    {
        return DeliveryOfferService::enabled() || $order->deliveryOffers()->exists();
    }

    /** @return array<string, mixed> */
    public static function data(Order $order): array
    {
        $now = now();
        $pending = DeliveryOffer::query()->pending()->where('order_id', $order->id)->with('driver')->first();
        $last = DeliveryOffer::query()->where('order_id', $order->id)->where('status', '!=', DeliveryOfferStatus::Pending->value)
            ->with('driver')->orderByDesc('responded_at')->orderByDesc('id')->first();

        $state = match (true) {
            $order->status === OrderStatus::Accepted && $pending !== null => 'offering',
            $order->status === OrderStatus::Accepted && $order->dispatch_state === Order::DISPATCH_NO_DRIVER => 'no_driver',
            $order->status === OrderStatus::Accepted && $order->dispatch_state === Order::DISPATCH_OFFERING => 'choosing',
            $order->status === OrderStatus::Accepted && $order->dispatch_round > 0 => 'paused',
            $order->status === OrderStatus::Accepted => 'not_started',
            $order->assigned_via_offer_id !== null && in_array($order->status, [OrderStatus::Assigned, OrderStatus::PickedUp, OrderStatus::Delivered], true) => 'accepted_by_driver',
            default => 'closed',
        };
        $color = match ($state) {
            'offering', 'choosing' => 'warning',
            'no_driver' => 'danger',
            'paused', 'not_started' => 'gray',
            'accepted_by_driver' => 'success',
            default => 'gray',
        };

        $cooldownEnds = $order->no_driver_since?->copy()->addMinutes(DeliveryDispatcher::recoveryCooldownMinutes());
        $lastAdminRound = (int) DeliveryOffer::query()->where('order_id', $order->id)->where('source', DeliveryOffer::SOURCE_ADMIN)->max('round');

        return [
            'enabled' => DeliveryOfferService::enabled(),
            'state' => $state,
            'state_label' => __("admin.dispatch.panel_state_{$state}"),
            'state_color' => $color,
            'needs_attention' => in_array($state, ['no_driver', 'paused'], true),
            'round' => (int) $order->dispatch_round,
            'attempts' => (int) $order->dispatch_attempts,
            'max_attempts' => DeliveryOfferService::maxAttemptsPerRound(),
            'pending' => $pending ? [
                'id' => $pending->id,
                'driver' => $pending->driver?->name ?? '#'.$pending->driver_id,
                'source' => __('admin.dispatch.source_'.$pending->source),
                'attempt' => $pending->attempt,
                'offered_at' => $pending->offered_at,
                'expires_at' => $pending->expires_at,
                'remaining_seconds' => $now->lessThan($pending->expires_at) ? (int) $now->diffInSeconds($pending->expires_at, true) : 0,
            ] : null,
            'last' => $last ? [
                'driver' => $last->driver?->name ?? '#'.$last->driver_id,
                'status' => $last->status->label(),
                'status_color' => self::statusColor($last->status),
                'reason' => self::reason($last),
                'at' => $last->responded_at,
            ] : null,
            'auto_recovery' => (bool) PlatformSettings::get('dispatch.auto_recovery'),
            'no_driver_since' => $order->no_driver_since,
            'alerted_at' => $order->no_driver_alerted_at,
            'next_auto_round_at' => $state === 'no_driver' ? $cooldownEnds : null,
            'auto_rounds_used' => max(0, (int) $order->dispatch_round - $lastAdminRound),
            'max_auto_rounds' => DeliveryDispatcher::maxAutomaticRounds(),
            'timeout_seconds' => DeliveryOfferService::timeoutSeconds(),
        ];
    }

    public static function statusColor(DeliveryOfferStatus $status): string
    {
        return match ($status) {
            DeliveryOfferStatus::Pending => 'warning',
            DeliveryOfferStatus::Accepted => 'success',
            DeliveryOfferStatus::Rejected => 'danger',
            DeliveryOfferStatus::Expired => 'gray',
            DeliveryOfferStatus::Cancelled => 'gray',
        };
    }

    /** The human reason of a closed offer (rejection or withdrawal), or null. */
    public static function reason(DeliveryOffer $offer): ?string
    {
        if ($offer->reject_reason) {
            return __('admin.dispatch.reject_'.$offer->reject_reason).($offer->reject_note ? ' — '.$offer->reject_note : '');
        }
        if ($offer->cancel_reason) {
            return __('admin.dispatch.cancel_'.$offer->cancel_reason);
        }

        return $offer->status === DeliveryOfferStatus::Expired ? __('admin.dispatch.reason_timeout') : null;
    }
}
