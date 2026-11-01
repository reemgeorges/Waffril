<?php

namespace App\Services\Catalog;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\User;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-10-31) — product ratings and their aggregate.
 *
 * products.rating_sum/rating_count always equal the VISIBLE ratings;
 * every write locks the product row and adjusts both in the same
 * transaction. One rating per (customer, product), only after a delivered
 * order containing the product; re-rating updates it.
 */
class ProductRatingService
{
    public function record(User $user, Product $product, Order $order, int $rating, ?string $comment): ProductRating
    {
        if ($rating < 1 || $rating > 5) {
            throw new BusinessRuleException(__('admin.errors.invalid_rating'));
        }

        $verified = $order->user_id === $user->id
            && $order->status === OrderStatus::Delivered
            && $order->items()->where('product_id', $product->id)->exists();
        if (! $verified) {
            throw new BusinessRuleException(__('admin.errors.rating_requires_delivered_purchase'));
        }

        return DB::transaction(function () use ($user, $product, $order, $rating, $comment) {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $existing = ProductRating::query()->where('user_id', $user->id)->where('product_id', $product->id)->lockForUpdate()->first();

            if ($existing) {
                if (! $existing->is_hidden) {
                    $this->adjust($product->id, $rating - $existing->rating, 0);
                }
                $existing->update(['rating' => $rating, 'comment' => $comment, 'order_id' => $order->id]);

                return $existing;
            }

            $this->adjust($product->id, $rating, 1);

            return ProductRating::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'order_id' => $order->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        });
    }

    public function hide(ProductRating $rating, Admin $admin, string $reason): ProductRating
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($rating, $admin, $reason) {
            Product::withTrashed()->lockForUpdate()->findOrFail($rating->product_id);
            $rating = ProductRating::query()->lockForUpdate()->findOrFail($rating->id);
            if ($rating->is_hidden) {
                return $rating;
            }

            $rating->forceFill(['is_hidden' => true, 'hidden_at' => now(), 'hidden_by_admin_id' => $admin->id, 'hidden_reason' => $reason])->save();
            $this->adjust($rating->product_id, -$rating->rating, -1);
            AdminAudit::log('rating.hidden', $rating, ['reason' => $reason], $admin);

            return $rating;
        });
    }

    public function unhide(ProductRating $rating, Admin $admin): ProductRating
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($rating, $admin) {
            Product::withTrashed()->lockForUpdate()->findOrFail($rating->product_id);
            $rating = ProductRating::query()->lockForUpdate()->findOrFail($rating->id);
            if (! $rating->is_hidden) {
                return $rating;
            }

            $rating->forceFill(['is_hidden' => false, 'hidden_at' => null, 'hidden_by_admin_id' => null, 'hidden_reason' => null])->save();
            $this->adjust($rating->product_id, $rating->rating, 1);
            AdminAudit::log('rating.unhidden', $rating, [], $admin);

            return $rating;
        });
    }

    protected function adjust(int $productId, int $sumDelta, int $countDelta): void
    {
        DB::table('products')->where('id', $productId)->update([
            'rating_sum' => DB::raw('rating_sum + ('.(int) $sumDelta.')'),
            'rating_count' => DB::raw('rating_count + ('.(int) $countDelta.')'),
        ]);
    }

    protected function authorize(Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'ratings.moderate')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }
}
