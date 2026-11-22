<?php

namespace Tests\Feature\Customer;

use App\Models\DriverRating;
use App\Models\Order;
use App\Models\OrderRating;

class OrderDriverRatingTest extends CustomerTestCase
{
    public function test_customer_can_rate_order_and_driver_independently_and_update_either(): void
    {
        $order = Order::query()->where('status', 'delivered')->whereNotNull('driver_id')->firstOrFail();
        $token = $this->loginCustomer($order->user);
        $url = "/api/v1/customer/orders/{$order->id}";

        $this->api($token)->putJson($url.'/rating', ['rating' => 5, 'comment' => 'Great order'])
            ->assertOk()->assertJsonPath('data.rating.rating', 5);
        $this->api($token)->putJson($url.'/driver-rating', ['rating' => 2, 'comment' => 'Driver was late'])
            ->assertOk()->assertJsonPath('data.rating.driver_id', $order->driver_id);
        $this->assertSame(1, OrderRating::where('order_id', $order->id)->count());
        $this->assertSame(1, DriverRating::where('order_id', $order->id)->count());

        $this->api($token)->putJson($url.'/rating', ['rating' => 3, 'comment' => null])->assertOk();
        $this->assertSame(3, OrderRating::where('order_id', $order->id)->value('rating'));
        $this->assertSame(2, DriverRating::where('order_id', $order->id)->value('rating'));
        $this->api($token)->getJson($url)
            ->assertOk()->assertJsonPath('data.order.ratings.order.rating', 3)
            ->assertJsonPath('data.order.ratings.driver.rating', 2)
            ->assertJsonPath('data.order.ratings.can_rate_order', true);
    }

    public function test_rating_rejects_other_customers_and_undelivered_orders(): void
    {
        $order = Order::query()->where('status', 'delivered')->firstOrFail();
        $other = \App\Models\User::query()->where('id', '!=', $order->user_id)->firstOrFail();
        $token = $this->loginCustomer($other);
        $this->api($token)->putJson("/api/v1/customer/orders/{$order->id}/rating", ['rating' => 5])->assertNotFound();
        $token = $this->loginCustomer($order->user, 'another-device');
        $pending = Order::query()->where('status', 'pending')->firstOrFail();
        $ownToken = $this->loginCustomer($pending->user, 'pending-device');
        $this->api($ownToken)->putJson("/api/v1/customer/orders/{$pending->id}/rating", ['rating' => 5])->assertStatus(422);
        $this->api($ownToken)->putJson("/api/v1/customer/orders/{$pending->id}/driver-rating", ['rating' => 5])->assertStatus(422);
    }

    public function test_invalid_rating_scores_and_legacy_route_are_rejected(): void
    {
        $order = Order::query()->where('status', 'delivered')->firstOrFail();
        $token = $this->loginCustomer($order->user);
        foreach ([0, 6, 'invalid'] as $score) {
            $this->api($token)->putJson("/api/v1/customer/orders/{$order->id}/rating", ['rating' => $score])->assertStatus(422);
        }
        $this->api($token)->putJson("/api/v1/customer/orders/{$order->id}/rating", ['rating' => 4, 'comment' => str_repeat('X', 1001)])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/customer/products/1/rating', ['order_id' => $order->id, 'rating' => 5])->assertNotFound();
    }

    public function test_order_with_no_driver_cannot_receive_driver_rating(): void
    {
        $order = Order::query()->where('status', 'delivered')->firstOrFail();
        $order->forceFill(['driver_id' => null])->save();
        $token = $this->loginCustomer($order->user);
        $this->api($token)->putJson("/api/v1/customer/orders/{$order->id}/rating", ['rating' => 4])->assertOk();
        $this->api($token)->putJson("/api/v1/customer/orders/{$order->id}/driver-rating", ['rating' => 4])->assertStatus(422);
    }
}
