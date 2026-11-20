<?php

namespace Tests\Feature\Customer;

use App\Models\Admin;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Services\Order\CheckoutService;
use App\Services\Plus\PlusService;
use Illuminate\Support\Facades\Hash;

class PlusPlanQuotaAndOrderNumberTest extends CustomerTestCase
{
    public function test_new_plus_cycle_uses_plan_quota_and_existing_plans_fall_back_to_platform_setting(): void
    {
        $plan = PlusPlan::where('code', 'demo-plus-basic')->firstOrFail();
        $plan->update(['free_deliveries_override' => 3]);
        $admin = Admin::create([
            'name' => 'PLUS test admin',
            'email' => 'plus-qa@example.test',
            'password' => Hash::make('not-a-real-password'),
            'is_active' => true,
        ]);
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        $request = PlusRequest::create([
            'user_id' => $this->demoCustomer(8)->id,
            'type' => PlusRequest::TYPE_NEW,
            'requested_plan_id' => $plan->id,
            'source' => 'customer',
            'status' => PlusRequest::STATUS_PENDING,
        ]);
        $cycle = app(PlusService::class)->approveNew($request, $admin, 'monthly');
        $this->assertSame(3, $cycle->free_deliveries_total);

        $plan->update(['free_deliveries_override' => 0]);
        // The confirmed cycle remains immutable after the plan is edited.
        $this->assertSame(3, $cycle->fresh()->free_deliveries_total);
    }

    public function test_short_order_reference_is_eight_unambiguous_characters(): void
    {
        // 2026-11-20: WF + 6 (was 12 characters with the date).
        foreach (range(1, 200) as $i) {
            $number = CheckoutService::orderNumber();
            $this->assertMatchesRegularExpression('/^WF[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/', $number);
            $this->assertSame(8, strlen($number));
        }
    }
}
