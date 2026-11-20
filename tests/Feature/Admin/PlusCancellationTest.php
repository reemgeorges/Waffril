<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\PlusMemberships\Pages\ViewPlusMembership;
use App\Filament\Resources\PlusRequests\Pages\ListPlusRequests;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Plus\PlusService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-11-20) — reported: a customer joined PLUS, cancelled, kept
 * getting the discount, then asked to join again and the admin could not
 * approve ("already has a membership").
 *
 * Root causes: cancelling only stopped renewal (the running cycle kept its
 * benefits until its end), and approving a new request required an ENDED
 * membership although the customer app accepts a new request as soon as the
 * membership is no longer active.
 */
class PlusCancellationTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    private function plan(): PlusPlan
    {
        Setting::query()->updateOrCreate(['key' => 'plus.free_deliveries_limit'], ['value' => 3]);

        return PlusPlan::create(['code' => 'gold-'.uniqid(), 'name' => ['en' => 'Gold', 'ar' => 'ذهبي'], 'free_deliveries_per_cycle' => 3]);
    }

    private function service(): PlusService
    {
        return app(PlusService::class);
    }

    /** The query pricing and checkout use to find the member's benefits. */
    private function benefitsApply(User $user): bool
    {
        return PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists();
    }

    private function member(User $user, PlusPlan $plan): PlusCycle
    {
        return $this->service()->approveNew(PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id]), $this->superAdmin(), PlusMembership::POLICY_MONTHLY);
    }

    public function test_cancelling_from_the_panel_ends_the_benefits_now_by_default_and_the_customer_can_join_again(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $this->actingAsAdmin($admin);
        $user = $this->customer();
        $plan = $this->plan();
        $cycle = $this->member($user, $plan);
        $this->assertTrue($this->benefitsApply($user));

        $cancel = PlusRequest::create(['user_id' => $user->id, 'type' => 'cancel']);
        // The admin approves without touching the toggle: default = end now.
        Livewire::test(ListPlusRequests::class)->callAction(TestAction::make('approveOther')->table($cancel))->assertHasNoActionErrors();

        $this->assertSame(PlusCycle::STATUS_CANCELLED, $cycle->fresh()->status);
        $this->assertSame(PlusMembership::STATUS_ENDED, $cycle->membership->fresh()->status);
        $this->assertFalse($this->benefitsApply($user), 'No discount or free delivery after cancelling.');

        // Joining again is approved straight away.
        $again = PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id]);
        $next = $this->service()->approveNew($again, $admin, PlusMembership::POLICY_MONTHLY);
        $this->assertSame(PlusCycle::STATUS_ACTIVE, $next->status);
        $this->assertSame(PlusMembership::STATUS_ACTIVE, $next->membership->fresh()->status);
        $this->assertSame(1, PlusMembership::query()->where('user_id', $user->id)->count(), 'Same membership row, reactivated.');
        $this->assertTrue($this->benefitsApply($user));
    }

    public function test_cancel_at_end_of_cycle_keeps_benefits_and_a_new_request_reactivates_without_overlap(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $user = $this->customer();
        $plan = $this->plan();
        $cycle = $this->member($user, $plan);

        $this->service()->approveCancellation(PlusRequest::create(['user_id' => $user->id, 'type' => 'cancel']), $admin, null, endNow: false);
        $this->assertSame(PlusMembership::STATUS_CANCELLED, $cycle->membership->fresh()->status);
        $this->assertTrue($this->benefitsApply($user), 'Chosen: benefits until the cycle ends.');

        // Was: BusinessRuleException "already has a membership" until the cycle ran out.
        $again = PlusRequest::create(['user_id' => $user->id, 'type' => 'new', 'requested_plan_id' => $plan->id]);
        $next = $this->service()->approveNew($again, $admin, PlusMembership::POLICY_MONTHLY);

        $this->assertSame(PlusMembership::STATUS_ACTIVE, $cycle->membership->fresh()->status);
        $this->assertNull($cycle->membership->fresh()->cancelled_at);
        $this->assertSame(PlusCycle::STATUS_ACTIVE, $cycle->fresh()->status, 'The running cycle continues.');
        $this->assertSame(PlusCycle::STATUS_SCHEDULED, $next->status);
        $this->assertEquals($cycle->ends_at, $next->starts_at, 'The new cycle starts when the running one ends.');
    }

    public function test_an_active_membership_still_refuses_a_second_new_request(): void
    {
        $this->usd();
        $user = $this->customer();
        $plan = $this->plan();
        $this->member($user, $plan);

        $this->expectException(BusinessRuleException::class);
        $this->member($user, $plan);
    }

    public function test_end_benefits_now_on_a_membership_already_cancelled_at_end_of_cycle(): void
    {
        $this->usd();
        $admin = $this->superAdmin();
        $this->actingAsAdmin($admin);
        $user = $this->customer();
        $cycle = $this->member($user, $this->plan());
        $membership = $cycle->membership;

        // Not offered while the membership is active.
        Livewire::test(ViewPlusMembership::class, ['record' => $membership->id])->assertActionHidden('endBenefitsNow');

        $this->service()->approveCancellation(PlusRequest::create(['user_id' => $user->id, 'type' => 'cancel']), $admin);
        $this->assertTrue($this->benefitsApply($user), 'Existing callers keep the end-of-cycle behaviour.');

        Livewire::test(ViewPlusMembership::class, ['record' => $membership->id])
            ->assertActionVisible('endBenefitsNow')
            ->callAction('endBenefitsNow')->assertHasNoActionErrors();

        $this->assertSame(PlusCycle::STATUS_CANCELLED, $cycle->fresh()->status);
        $this->assertSame(PlusMembership::STATUS_ENDED, $membership->fresh()->status);
        $this->assertFalse($this->benefitsApply($user));

        $this->expectException(BusinessRuleException::class);
        $this->service()->endBenefitsNow($membership->fresh()->forceFill(['status' => PlusMembership::STATUS_ACTIVE]), $admin);
    }
}
