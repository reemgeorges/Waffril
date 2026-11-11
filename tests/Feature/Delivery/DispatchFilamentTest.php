<?php

namespace Tests\Feature\Delivery;

use App\Enums\DeliveryOfferStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\PlatformSettingsPage;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\DeliveryOffersRelationManager;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\City;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Customer\CustomerOrderService;
use App\Services\Delivery\DeliveryOfferService;
use App\Services\Driver\DriverRecommendationService;
use App\Services\Order\OrderAdminService;
use App\Services\Platform\PlatformSettingsService;
use App\Support\Auth\AdminPermissions;
use App\Support\Broadcasting\RecordingBroadcaster;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Admin\AdminPanelTestCase;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI dispatch B4 (2026-11-10) — delivery dispatch in the Filament
 * order page and list: actions, permissions, the live panel, history, the
 * "Waiting for driver" queue, settings, and the unchanged workflow while
 * delivery offers are disabled.
 */
class DispatchFilamentTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected Admin $admin;

    protected City $cityModel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dispatch.offers_enabled' => true]);
        RecordingBroadcaster::reset();
        $this->admin = $this->superAdmin();
        $this->cityModel = $this->city();
    }

    protected function acceptedOrder(): Order
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->cityModel), '10');

        return app(OrderAdminService::class)->accept($order, $this->admin)->fresh();
    }

    protected function newDriver(array $attributes = []): Driver
    {
        return $this->driver($this->cityModel, $attributes);
    }

    protected function page(Order $order)
    {
        return Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    }

    protected function audited(string $action, Order $order): ?AdminAuditLog
    {
        return AdminAuditLog::query()->where('action', $action)
            ->where('auditable_type', $order->getMorphClass())->where('auditable_id', $order->id)->latest('id')->first();
    }

    public function test_the_existing_direct_assignment_is_unchanged_while_offers_are_disabled(): void
    {
        config(['dispatch.offers_enabled' => false]);
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();

        $this->page($order)
            ->assertActionVisible('assign')
            ->assertActionHidden('sendOffer')->assertActionHidden('restartDispatch')
            ->assertActionHidden('withdrawOffer')->assertActionHidden('forceAssign')
            ->assertDontSee(__('admin.dispatch.panel_title'))
            ->callAction('assign', data: ['driver_id' => $driver->id]);

        $this->assertSame([OrderStatus::Assigned, $driver->id], [$order->fresh()->status, (int) $order->fresh()->driver_id]);
        $this->assertSame(0, DeliveryOffer::count());
        $this->assertArrayNotHasKey('waiting_for_driver', (new ListOrders)->getTabs(), 'No queue tab while offers are off and nothing waits.');
    }

    public function test_sending_an_offer_keeps_the_order_accepted_until_the_driver_accepts(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        $driver = $this->newDriver(['name' => 'Samer Offer']);

        $this->page($order)
            ->assertActionHidden('assign') // direct assignment cannot bypass acceptance
            ->assertActionVisible('sendOffer')
            ->assertSee(__('admin.dispatch.panel_state_not_started'))
            ->callAction('sendOffer', data: ['driver_id' => $driver->id])
            ->assertSee('Samer Offer')
            ->assertSee(__('admin.dispatch.panel_state_offering'))
            ->assertSeeHtml('wire:poll.5s="refreshDispatch"');

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status);
        $this->assertNull($order->driver_id, 'driver_id is set only on acceptance.');
        $offer = DeliveryOffer::query()->pending()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame([$driver->id, DeliveryOffer::SOURCE_ADMIN, $this->admin->id], [(int) $offer->driver_id, $offer->source, (int) $offer->offered_by_admin_id]);
        $this->assertSame(90, (int) $offer->offered_at->diffInSeconds($offer->expires_at));
        $this->assertNotNull($this->audited('order.offer_sent', $order));
        $this->assertNotEmpty(RecordingBroadcaster::on('private-admin.orders', 'admin.operational'), 'Open admin screens refresh.');

        // The driver accepts: the order becomes assigned; the panel says so after a refresh.
        app(DeliveryOfferService::class)->accept($offer, $driver);
        $this->page($order)->assertSee(__('admin.dispatch.panel_state_accepted_by_driver'));
        $this->assertSame([OrderStatus::Assigned, $driver->id], [$order->fresh()->status, (int) $order->fresh()->driver_id]);
    }

    public function test_only_eligible_drivers_can_be_chosen(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        $offline = $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE, 'name' => 'Offline Driver']);
        $ready = $this->newDriver(['name' => 'Ready Driver']);

        // The driver list is the dispatch candidates of a new round: only eligible drivers.
        $listed = app(DriverRecommendationService::class)->dispatchCandidates($order, $order->dispatch_round + 1, 50)->map(fn ($row) => $row['driver']->id)->all();
        $this->assertSame([$ready->id], $listed);
        $this->page($order)->callAction('sendOffer', data: ['driver_id' => null])->assertHasActionErrors(['driver_id' => 'required']);

        // Even a forged choice of an ineligible driver is refused by the service.
        $this->page($order)->callAction('sendOffer', data: ['driver_id' => $offline->id]);
        $this->assertSame(0, DeliveryOffer::count());
    }

    public function test_permissions_hide_actions_and_the_service_refuses_anyway(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();

        $viewer = $this->employee(['orders.view']);
        $this->actingAsAdmin($viewer);
        $this->page($order)->assertActionHidden('sendOffer')->assertActionHidden('forceAssign');
        try {
            app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $viewer);
            $this->fail('A viewer cannot send offers.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }

        $dispatcher = $this->employee(['orders.view', 'orders.assign']);
        $this->actingAsAdmin($dispatcher);
        $this->page($order)->assertActionVisible('sendOffer')->assertActionHidden('forceAssign');
        try {
            app(OrderAdminService::class)->forceAssignDriver($order, $driver, $dispatcher, 'not allowed to force');
            $this->fail('Force assignment needs orders.force_assign.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }
        $this->assertTrue(Permission::where('name', 'orders.force_assign')->exists(), 'The new permission is seeded.');
        $this->assertContains('orders.force_assign', AdminPermissions::all());
    }

    public function test_the_panel_follows_rejection_expiry_and_automatic_reassignment(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        $first = $this->newDriver(['name' => 'First Driver']);
        $this->newDriver(['name' => 'Second Driver']);
        $third = $this->newDriver(['name' => 'Third Driver']);
        $page = $this->page($order)->callAction('sendOffer', data: ['driver_id' => $first->id]);

        app(DeliveryOfferService::class)->reject(DeliveryOffer::query()->pending()->firstOrFail(), $first, 'too_far', 'far away');
        $page->call('refreshDispatch')
            ->assertSee(__('admin.dispatch.status_rejected'))->assertSee(__('admin.dispatch.reject_too_far'))
            ->assertSee('Second Driver')->assertSee(__('admin.dispatch.round_attempts', ['round' => 1, 'attempts' => 2, 'max' => 10]));

        $this->travel(91)->seconds();
        $this->artisan('waffrli:dispatch-sweep')->assertSuccessful();
        $page->call('refreshDispatch')->assertSee(__('admin.dispatch.reason_timeout'))->assertSee('Third Driver');
        $this->assertSame($third->id, (int) DeliveryOffer::query()->pending()->firstOrFail()->driver_id);
    }

    public function test_offer_history_is_complete_and_readable(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        $first = $this->newDriver(['name' => 'History One']);
        $this->newDriver(['name' => 'History Two']);
        app(DeliveryOfferService::class)->offerByAdmin($order, $first, $this->admin);
        app(DeliveryOfferService::class)->reject(DeliveryOffer::query()->pending()->firstOrFail(), $first, 'vehicle_issue', 'flat tyre');
        $offers = DeliveryOffer::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $offers);

        Livewire::test(DeliveryOffersRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords($offers, inOrder: true)
            ->assertSee('History One')->assertSee('History Two')
            ->assertSee(__('admin.dispatch.source_admin'))->assertSee(__('admin.dispatch.source_auto'))
            ->assertSee(__('admin.dispatch.reject_vehicle_issue').' — flat tyre')
            ->assertSee(__('admin.dispatch.meta_rank', ['rank' => 1]));
    }

    public function test_withdraw_pauses_and_restart_starts_a_new_admin_round(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        [$first, $second] = [$this->newDriver(), $this->newDriver()];
        $this->page($order)->callAction('sendOffer', data: ['driver_id' => $first->id]);
        $offer = DeliveryOffer::query()->pending()->firstOrFail();

        $this->page($order)->assertActionVisible('withdrawOffer')->callAction('withdrawOffer')
            ->assertSee(__('admin.dispatch.panel_state_paused'));
        $this->assertSame([DeliveryOfferStatus::Cancelled, DeliveryOffer::CANCEL_ADMIN_WITHDRAWN], [$offer->fresh()->status, $offer->fresh()->cancel_reason]);
        $this->assertNull($order->fresh()->dispatch_state);
        $this->assertSame(0, DeliveryOffer::query()->pending()->count(), 'Withdrawal pauses: nobody else is offered.');
        $this->assertNotNull($this->audited('order.offer_withdrawn', $order));

        $this->page($order)->assertActionHidden('sendOffer')->assertActionVisible('restartDispatch')
            ->callAction('restartDispatch', data: ['driver_id' => $second->id]);
        $restart = DeliveryOffer::query()->pending()->firstOrFail();
        $this->assertSame([$second->id, 2, 1, DeliveryOffer::SOURCE_ADMIN], [(int) $restart->driver_id, $restart->round, $restart->attempt, $restart->source]);
        $this->assertNotNull($this->audited('order.dispatch_restarted', $order));

        // Restarting while an offer is pending replaces it atomically.
        $this->page($order)->callAction('restartDispatch', data: ['driver_id' => $first->id]);
        $this->assertSame(DeliveryOffer::CANCEL_ADMIN_RESTART, $restart->fresh()->cancel_reason);
        $this->assertSame([$first->id, 3], [(int) DeliveryOffer::query()->pending()->firstOrFail()->driver_id, DeliveryOffer::query()->pending()->firstOrFail()->round]);
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
    }

    public function test_emergency_force_assignment_is_explicit_audited_and_atomic(): void
    {
        $this->actingAsAdmin($this->admin);
        $order = $this->acceptedOrder();
        [$offered, $chosen] = [$this->newDriver(), $this->newDriver()];
        app(DeliveryOfferService::class)->offerByAdmin($order, $offered, $this->admin);
        $offer = DeliveryOffer::query()->pending()->firstOrFail();

        $this->page($order)->assertActionVisible('forceAssign')
            ->callAction('forceAssign', data: ['driver_id' => $chosen->id, 'reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame(DeliveryOfferStatus::Pending, $offer->fresh()->status);

        $this->page($order)->callAction('forceAssign', data: ['driver_id' => $chosen->id, 'reason' => 'Driver app outage, customer waiting']);

        $this->assertSame([OrderStatus::Assigned, $chosen->id], [$order->fresh()->status, (int) $order->fresh()->driver_id]);
        $this->assertNull($order->fresh()->assigned_via_offer_id);
        $this->assertSame(DeliveryOffer::CANCEL_ADMIN_OVERRIDE, $offer->fresh()->cancel_reason);
        $log = $this->audited('order.driver_force_assigned', $order);
        $this->assertNotNull($log);
        $this->assertSame('Driver app outage, customer waiting', $log->properties['reason'] ?? null);
        $this->assertSame($offer->id, (int) ($log->properties['pending_offer_id'] ?? 0));

        // The usual eligibility rules still apply.
        $other = $this->acceptedOrder();
        try {
            app(OrderAdminService::class)->forceAssignDriver($other, $this->newDriver(['availability' => Driver::AVAILABILITY_OFFLINE]), $this->admin, 'should be refused');
            $this->fail('An offline driver cannot be force-assigned.');
        } catch (BusinessRuleException) {
            $this->assertSame(OrderStatus::Accepted, $other->fresh()->status);
        }
        // And plain direct assignment is refused while offers are on.
        try {
            app(OrderAdminService::class)->assignDriver($other, $this->newDriver(), $this->admin);
            $this->fail('Direct assignment must not bypass acceptance.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }
    }

    public function test_concurrent_admin_actions_never_create_conflicting_offers(): void
    {
        $order = $this->acceptedOrder();
        [$a, $b] = [$this->newDriver(), $this->newDriver()];
        $adminTwo = $this->superAdmin();

        // Two admins open the same page; the first sends an offer.
        $this->actingAsAdmin($this->admin);
        $pageOne = $this->page($order);
        $this->actingAsAdmin($adminTwo);
        $pageTwo = $this->page($order);
        $this->actingAsAdmin($this->admin);
        $pageOne->callAction('sendOffer', data: ['driver_id' => $a->id]);

        // The second admin's stale page: the action is re-checked on use and is gone,
        // and a direct service call is refused too. Still exactly one pending offer.
        $this->actingAsAdmin($adminTwo);
        $pageTwo->assertActionHidden('sendOffer');
        try {
            app(DeliveryOfferService::class)->offerByAdmin($order, $b, $adminTwo);
            $this->fail('A second offer must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }
        $this->assertSame(1, DeliveryOffer::query()->pending()->where('order_id', $order->id)->count());
        $this->assertSame($a->id, (int) DeliveryOffer::query()->pending()->firstOrFail()->driver_id);

        // Both withdraw: the second finds nothing left and is told so.
        app(DeliveryOfferService::class)->withdrawByAdmin($order, $this->admin);
        try {
            app(DeliveryOfferService::class)->withdrawByAdmin($order, $adminTwo);
            $this->fail('Nothing left to withdraw.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->getStatus());
        }
    }

    public function test_the_waiting_for_driver_queue_lists_and_highlights_orders(): void
    {
        $this->actingAsAdmin($this->admin);
        $offering = $this->acceptedOrder();
        $stuck = $this->acceptedOrder();
        $quiet = $this->acceptedOrder();
        app(DeliveryOfferService::class)->offerByAdmin($offering, $this->newDriver(), $this->admin);
        $lonely = $this->newDriver();
        app(DeliveryOfferService::class)->offerByAdmin($stuck, $lonely, $this->admin);
        $lonely->forceFill(['availability' => Driver::AVAILABILITY_OFFLINE])->save();
        app(DeliveryOfferService::class)->reject(DeliveryOffer::query()->pending()->where('order_id', $stuck->id)->firstOrFail(), $lonely, 'busy');
        $this->assertSame(Order::DISPATCH_NO_DRIVER, $stuck->fresh()->dispatch_state);

        $tabs = (new ListOrders)->getTabs();
        // The dispatch queue sits among the work tabs (UX redesign 2026-11-11).
        $this->assertArrayHasKey('waiting_for_driver', $tabs);
        $this->assertSame('all', array_key_first($tabs));

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'waiting_for_driver')
            ->assertCanSeeTableRecords([$stuck, $offering], inOrder: true) // no-driver orders first
            ->assertCanNotSeeTableRecords([$quiet])
            ->assertSee(__('admin.dispatch.state_no_driver'))
            ->assertSee(__('admin.dispatch.state_offering'))
            ->assertSeeHtml('waffrli-row-no-driver');
    }

    public function test_dispatch_settings_are_validated_and_exposed(): void
    {
        $service = app(PlatformSettingsService::class);
        foreach ([['dispatch.offer_timeout_seconds', 10], ['dispatch.offer_timeout_seconds', 301], ['dispatch.max_attempts_per_round', 0],
            ['dispatch.max_auto_rounds', 11], ['dispatch.location_fresh_minutes', 0], ['dispatch.rotation_strategy', 'random']] as [$key, $value]) {
            try {
                $service->save([$key => $value], $this->admin);
                $this->fail("{$key}={$value} must be refused.");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }
        $service->save(['dispatch.offer_timeout_seconds' => 120, 'dispatch.location_fresh_minutes' => 5, 'dispatch.rotation_strategy' => 'fair_rotation'], $this->admin);
        $this->assertSame(120, DeliveryOfferService::timeoutSeconds());

        $this->actingAsAdmin($this->admin);
        Livewire::test(PlatformSettingsPage::class)
            ->assertSee(__('admin.settings.groups.dispatch'))
            ->assertSee(__('admin.settings.dispatch_enabled'))
            ->assertSee(__('admin.settings.keys.dispatch.location_fresh_minutes'))
            ->assertSee(__('admin.settings.options.fair_rotation'));
    }

    public function test_customers_see_the_same_order_states_as_before(): void
    {
        $order = $this->acceptedOrder();
        $driver = $this->newDriver();
        $offer = app(DeliveryOfferService::class)->offerByAdmin($order, $driver, $this->admin);

        $view = app(CustomerOrderService::class)->present($order->fresh());
        $this->assertSame('accepted', $view['status']);
        $this->assertNull($view['driver'] ?? null);
        $this->assertArrayNotHasKey('dispatch_state', $view);

        app(DeliveryOfferService::class)->accept($offer, $driver);
        $this->assertSame('assigned', app(CustomerOrderService::class)->present($order->fresh())['status']);
    }
}
