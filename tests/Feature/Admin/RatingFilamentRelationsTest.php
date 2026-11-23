<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\DriverRatingsRelationManager as CustomerDriverRatings;
use App\Filament\Resources\Customers\RelationManagers\OrderRatingsRelationManager as CustomerOrderRatings;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\RelationManagers\RatingsRelationManager as DriverRatings;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\DriverRatingsRelationManager as OrderDriverRatings;
use App\Filament\Resources\Orders\RelationManagers\OrderRatingsRelationManager as OrderOrderRatings;
use App\Filament\Resources\Orders\RelationManagers\RatingsRelationManager;
use App\Models\DriverRating;
use App\Models\OrderRating;
use App\Services\Ratings\OrderDriverRatingService;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

class RatingFilamentRelationsTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_an_order_has_two_independent_rating_tabs_and_no_product_rating_tab(): void
    {
        $city = $this->city();
        $store = $this->store();
        $driver = $this->driver($city);
        $customer = $this->customer();
        $order = $this->order($customer, $this->branch($store, $city), '9', [
            'status' => OrderStatus::Delivered, 'driver' => $driver,
        ]);
        $ratings = app(OrderDriverRatingService::class);
        $overall = $ratings->saveOrderRating($customer, $order->id, 5, 'Order was good');
        $courier = $ratings->saveDriverRating($customer, $order->id, 3, 'Late but polite');

        $tabs = OrderResource::getRelations();
        $this->assertContains(OrderOrderRatings::class, $tabs);
        $this->assertContains(OrderDriverRatings::class, $tabs);
        $this->assertNotContains(RatingsRelationManager::class, $tabs);

        $this->actingAsAdmin($this->superAdmin());
        Livewire::test(OrderOrderRatings::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$overall]);
        Livewire::test(OrderDriverRatings::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$courier]);
        Livewire::test(DriverRatings::class, ['ownerRecord' => $driver, 'pageClass' => EditDriver::class])
            ->assertCanSeeTableRecords([$courier]);
        Livewire::test(CustomerOrderRatings::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$overall]);
        Livewire::test(CustomerDriverRatings::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$courier]);
    }

    public function test_no_feedback_for_an_unrated_order(): void
    {
        $city = $this->city();
        $customer = $this->customer();
        $order = $this->order($customer, $this->branch($this->store(), $city), '9', [
            'status' => OrderStatus::Delivered,
        ]);

        $this->assertSame(0, OrderRating::where('order_id', $order->id)->count());
        $this->assertSame(0, DriverRating::where('order_id', $order->id)->count());
        $this->actingAsAdmin($this->superAdmin());
        Livewire::test(OrderOrderRatings::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCountTableRecords(0);
        Livewire::test(OrderDriverRatings::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCountTableRecords(0);
    }
}
