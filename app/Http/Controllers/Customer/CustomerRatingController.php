<?php

namespace App\Http\Controllers\Customer;

use App\Services\Ratings\OrderDriverRatingService;
use Illuminate\Http\Request;

class CustomerRatingController extends CustomerController
{
    public function __construct(protected OrderDriverRatingService $ratings) {}

    public function order(Request $request, string $order)
    {
        return $this->respond(function () use ($request, $order) {
            $data = $request->validate([
                'rating' => ['required', 'integer', 'between:1,5'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);
            $review = $this->ratings->saveOrderRating(
                $this->customer($request), $order, (int) $data['rating'], $data['comment'] ?? null
            );

            return [
                'message' => __('order_driver_ratings.saved'),
                'rating' => ['rating' => $review->rating, 'comment' => $review->comment, 'is_hidden' => $review->is_hidden,
                    'created_at' => $review->created_at?->toIso8601String(), 'updated_at' => $review->updated_at?->toIso8601String()],
            ];
        });
    }

    public function driver(Request $request, string $order)
    {
        return $this->respond(function () use ($request, $order) {
            $data = $request->validate([
                'rating' => ['required', 'integer', 'between:1,5'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);
            $review = $this->ratings->saveDriverRating(
                $this->customer($request), $order, (int) $data['rating'], $data['comment'] ?? null
            );

            return [
                'message' => __('order_driver_ratings.saved'),
                'rating' => ['rating' => $review->rating, 'comment' => $review->comment, 'is_hidden' => $review->is_hidden,
                    'driver_id' => $review->driver_id,
                    'created_at' => $review->created_at?->toIso8601String(), 'updated_at' => $review->updated_at?->toIso8601String()],
            ];
        });
    }
}
