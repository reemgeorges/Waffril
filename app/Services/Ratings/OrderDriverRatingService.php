<?php

namespace App\Services\Ratings;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DriverRating;
use App\Models\Order;
use App\Models\OrderRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Only the owning customer can rate a delivered order; driver is server-derived. */
class OrderDriverRatingService
{
    public function saveOrderRating(User $user, int|string $orderId, int $score, ?string $comment): OrderRating
    {
        $this->validateInput($score, $comment);
        return DB::transaction(function () use ($user, $orderId, $score, $comment) {
            $order = $this->lockEligibleOrder($user, $orderId, false);
            return OrderRating::query()->updateOrCreate(
                ['order_id' => $order->id],
                ['user_id' => $user->id, 'rating' => $score, 'comment' => $comment]
            );
        });
    }

    public function saveDriverRating(User $user, int|string $orderId, int $score, ?string $comment): DriverRating
    {
        $this->validateInput($score, $comment);
        return DB::transaction(function () use ($user, $orderId, $score, $comment) {
            $order = $this->lockEligibleOrder($user, $orderId, true);
            return DriverRating::query()->updateOrCreate(
                ['order_id' => $order->id],
                ['user_id' => $user->id, 'driver_id' => $order->driver_id,
                    'rating' => $score, 'comment' => $comment]
            );
        });
    }

    protected function validateInput(int $score, ?string $comment): void
    {
        if ($score < 1 || $score > 5 || ($comment !== null && mb_strlen($comment) > 1000)) {
            throw new BusinessRuleException(__('admin.errors.invalid_rating'), 422);
        }
    }

    protected function lockEligibleOrder(User $user, int|string $orderId, bool $driver): Order
    {
        // Locking the parent serializes competing PUTs before the UNIQUE(order_id).
        $order = Order::query()->whereKey($orderId)->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $order) {
            throw new BusinessRuleException(__('messages.customer.not_found'), 404);
        }
        if ($order->status !== OrderStatus::Delivered) {
            throw new BusinessRuleException(__('order_driver_ratings.delivered_only'), 422);
        }
        if ($driver && $order->driver_id === null) {
            throw new BusinessRuleException(__('order_driver_ratings.driver_missing'), 422);
        }
        return $order;
    }
}
