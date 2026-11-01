<?php

namespace Database\Seeders\Demo;

use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\PlusStoreDiscount;
use App\Models\Store;
use App\Models\User;
use App\Services\Plus\PlusService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * WAFFRLI demo — PLUS plans with store benefits and member histories built
 * ONLY through App\Services\Plus\PlusService (admin-confirmed cycles with
 * immutable snapshots), back-dated with time travel:
 *
 *  customer 1: Gold, monthly — active cycle + scheduled renewal whose
 *              snapshot reflects later exceptions and a free-delivery override;
 *              pending plan-change request.
 *  customer 2: Basic, custom 20 days — two expired cycles, one active;
 *              pending cancellation request.
 *  customer 3: Gold, one-time — cancelled, cycle expired, membership ended.
 *  customer 4: pending new request · customer 5: rejected request ·
 *  customer 6: pending custom request · customer 7: resolved custom request.
 */
class DemoPlusSeeder extends Seeder
{
    public const PLANS = [
        'demo-plus-basic' => ['بلس الأساسي', 'PLUS Basic', 2, '4.99', 30, 1],
        'demo-plus-gold' => ['بلس الذهبي', 'PLUS Gold', 5, '9.99', 30, 2],
        'demo-plus-family' => ['بلس العائلة', 'PLUS Family', 8, '14.99', 30, 3],
    ];

    /** plan => [store slug, type, value, max, min order] */
    public const BENEFITS = [
        'demo-plus-basic' => [['demo-pizza-roma', 'percent', '5', '2.00', null]],
        'demo-plus-gold' => [['demo-shami-grill', 'percent', '10', '3.00', null], ['demo-fresh-market', 'fixed', '1.00', null, '10.00'], ['demo-pizza-roma', 'percent', '5', '2.00', null]],
        'demo-plus-family' => [['demo-shami-grill', 'percent', '15', '5.00', null], ['demo-fresh-market', 'fixed', '1.50', null, '8.00']],
    ];

    protected DemoContext $context;

    protected PlusService $plus;

    public function run(): void
    {
        $this->context = app(DemoContext::class);
        $this->plus = app(PlusService::class);

        $this->context->at($this->context->anchor()->subDays(70), fn () => $this->plans());

        $this->goldMonthly(DemoPeopleSeeder::customer(1));
        $this->basicCustom(DemoPeopleSeeder::customer(2));
        $this->goldOneTimeCancelled(DemoPeopleSeeder::customer(3));
        $this->requestsOnly();

        $this->context->at($this->context->anchor(), fn () => $this->plus->syncCycles());
    }

    protected function plans(): void
    {
        $admin = $this->context->operator();
        foreach (self::PLANS as $code => [$ar, $en, $free, $price, $days, $sort]) {
            $plan = PlusPlan::withTrashed()->where('code', $code)->first();
            if ($plan) {
                continue;
            }
            $plan = PlusPlan::create([
                'code' => $code, 'name' => ['ar' => $ar, 'en' => $en],
                'description' => ['ar' => "خطة تجريبية: {$ar}", 'en' => "Demo plan: {$en}"],
                'default_duration_days' => $days, 'free_deliveries_per_cycle' => $free, 'display_price' => $price,
                'is_active' => true, 'sort_order' => $sort,
            ]);
            foreach (self::BENEFITS[$code] as [$storeSlug, $type, $value, $max, $min]) {
                PlusStoreDiscount::create([
                    'plus_plan_id' => $plan->id, 'store_id' => Store::query()->where('slug', $storeSlug)->firstOrFail()->id,
                    'is_excluded' => false, 'discount_type' => $type, 'discount_value' => $value,
                    'max_discount_amount' => $max, 'min_order_amount' => $min,
                    'approved_by_admin_id' => $admin->id, 'is_active' => true,
                ]);
            }
        }
    }

    protected function goldMonthly(User $user): void
    {
        if (PlusMembership::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $admin = $this->context->operator();
        $gold = $this->plan('demo-plus-gold');

        $request = $this->at(-11 * 24 * 60, fn () => $this->request($user, PlusRequest::TYPE_NEW, $gold, 'I order a lot from Shami Grill.'));
        $this->at(-10 * 24 * 60, fn () => $this->plus->approveNew($request, $admin, PlusMembership::POLICY_MONTHLY, note: 'Demo approval'));

        $membership = PlusMembership::query()->where('user_id', $user->id)->firstOrFail();
        $this->at(-2 * 24 * 60, function () use ($membership, $admin) {
            $this->plus->setFreeDeliveriesOverride($membership, 6, $admin);
            $this->plus->saveException($membership, $this->store('demo-fresh-market'), ['is_excluded' => true, 'note' => 'Demo: store asked to exclude this member'], $admin);
            $this->plus->saveException($membership, $this->store('demo-pizza-roma'), ['discount_type' => 'percent', 'discount_value' => '8', 'max_discount_amount' => '3.00', 'note' => 'Demo: loyalty exception'], $admin);
        });

        $renewal = $this->at(-26 * 60, fn () => $this->request($user, PlusRequest::TYPE_RENEW, null, null));
        $this->at(-24 * 60, fn () => $this->plus->approveRenewal($renewal, $admin, 'Demo: renewal confirmed'));

        $this->at(-60, fn () => $this->request($user, PlusRequest::TYPE_CHANGE, $this->plan('demo-plus-family'), 'Can I move to the family plan?'));
    }

    protected function basicCustom(User $user): void
    {
        if (PlusMembership::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $admin = $this->context->operator();
        $day = 24 * 60;

        $request = $this->at(-46 * $day, fn () => $this->request($user, PlusRequest::TYPE_NEW, $this->plan('demo-plus-basic'), null));
        $this->at(-45 * $day, fn () => $this->plus->approveNew($request, $admin, PlusMembership::POLICY_CUSTOM, 20));

        $second = $this->at(-27 * $day, fn () => $this->request($user, PlusRequest::TYPE_RENEW, null, null));
        $this->at(-26 * $day, fn () => $this->plus->approveRenewal($second, $admin));
        $this->at(-25 * $day + 5, fn () => $this->plus->syncCycles());

        $third = $this->at(-7 * $day, fn () => $this->request($user, PlusRequest::TYPE_RENEW, null, null));
        $this->at(-6 * $day, fn () => $this->plus->approveRenewal($third, $admin));
        $this->at(-5 * $day + 5, fn () => $this->plus->syncCycles());

        $this->at(-120, fn () => $this->request($user, PlusRequest::TYPE_CANCEL, null, 'Travelling for two months.'));
    }

    protected function goldOneTimeCancelled(User $user): void
    {
        if (PlusMembership::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $admin = $this->context->operator();
        $day = 24 * 60;

        $request = $this->at(-61 * $day, fn () => $this->request($user, PlusRequest::TYPE_NEW, $this->plan('demo-plus-gold'), null));
        $this->at(-60 * $day, fn () => $this->plus->approveNew($request, $admin, PlusMembership::POLICY_ONE_TIME));

        $cancel = $this->at(-40 * $day, fn () => $this->request($user, PlusRequest::TYPE_CANCEL, null, 'Please do not renew.'));
        $this->at(-39 * $day, fn () => $this->plus->approveCancellation($cancel, $admin, 'Demo: cancelled at member request'));
        $this->at(-29 * $day, fn () => $this->plus->syncCycles());
    }

    protected function requestsOnly(): void
    {
        $admin = $this->context->operator();
        $day = 24 * 60;

        $c4 = DemoPeopleSeeder::customer(4);
        if (! PlusRequest::query()->where('user_id', $c4->id)->exists()) {
            $this->at(-1 * $day, fn () => $this->request($c4, PlusRequest::TYPE_NEW, $this->plan('demo-plus-basic'), 'Interested in free deliveries.'));
        }

        $c5 = DemoPeopleSeeder::customer(5);
        if (! PlusRequest::query()->where('user_id', $c5->id)->exists()) {
            $rejected = $this->at(-8 * $day, fn () => $this->request($c5, PlusRequest::TYPE_NEW, $this->plan('demo-plus-gold'), null));
            $this->at(-7 * $day, fn () => $this->plus->reject($rejected, $admin, 'Demo: subscription fee not received'));
        }

        $c6 = DemoPeopleSeeder::customer(6);
        if (! PlusRequest::query()->where('user_id', $c6->id)->exists()) {
            $this->at(-3 * 60, fn () => $this->request($c6, PlusRequest::TYPE_CUSTOM, null, 'A shared plan for my office (5 people)?'));
        }

        $c7 = DemoPeopleSeeder::customer(7);
        if (! PlusRequest::query()->where('user_id', $c7->id)->exists()) {
            $custom = $this->at(-16 * $day, fn () => $this->request($c7, PlusRequest::TYPE_CUSTOM, null, 'Student discount?'));
            $this->at(-15 * $day, fn () => $this->plus->resolveCustom($custom, $admin, 'Demo: explained that no student plan exists yet'));
        }
    }

    protected function request(User $user, string $type, ?PlusPlan $plan, ?string $note): PlusRequest
    {
        return PlusRequest::create([
            'user_id' => $user->id, 'type' => $type, 'requested_plan_id' => $plan?->id,
            'customer_note' => $note, 'source' => 'customer',
        ]);
    }

    /** Runs at anchor + $minutes (negative = in the past). */
    protected function at(int $minutes, callable $callback): mixed
    {
        return $this->context->at(Carbon::instance($this->context->anchor())->addMinutes($minutes), $callback);
    }

    protected function plan(string $code): PlusPlan
    {
        return PlusPlan::query()->where('code', $code)->firstOrFail();
    }

    protected function store(string $slug): Store
    {
        return Store::query()->where('slug', $slug)->firstOrFail();
    }
}
