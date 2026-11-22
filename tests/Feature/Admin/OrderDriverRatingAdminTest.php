<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\DriverRating;
use App\Models\OrderRating;
use App\Services\Ratings\OrderDriverRatingService;
use App\Services\Ratings\RatingModerationService;
use Tests\Support\WaffrliFixtures;

class OrderDriverRatingAdminTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_admin_moderation_affects_driver_average_without_changing_customer_rating(): void
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $driver = $this->driver($city);
        $customer = $this->customer();
        $order = $this->order($customer, $branch, '10', ['status' => OrderStatus::Delivered, 'driver' => $driver]);
        $service = app(OrderDriverRatingService::class);
        $service->saveOrderRating($customer, $order->id, 5, 'Great');
        $review = $service->saveDriverRating($customer, $order->id, 2, 'Late');

        $this->assertSame(2.0, round((float) $driver->ratings()->where('is_hidden', false)->avg('rating'), 2));
        app(RatingModerationService::class)->setHidden($review, $admin, true, 'Test moderation');
        $this->assertSame(0, $driver->ratings()->where('is_hidden', false)->count());
        $this->assertSame(5, OrderRating::where('order_id', $order->id)->value('rating'));
        app(RatingModerationService::class)->setHidden($review, $admin, false);
        $this->assertSame(1, DriverRating::where('order_id', $order->id)->where('is_hidden', false)->count());
    }

    public function test_employee_without_moderation_permission_cannot_hide_review(): void
    {
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $customer = $this->customer();
        $order = $this->order($customer, $branch, '10', ['status' => OrderStatus::Delivered]);
        $review = app(OrderDriverRatingService::class)->saveOrderRating($customer, $order->id, 4, 'Fine');
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(RatingModerationService::class)->setHidden($review, $this->employee(['orders.view']), true, 'Not permitted');
    }
}
