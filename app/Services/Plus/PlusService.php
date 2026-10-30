<?php

namespace App\Services\Plus;

use App\Notifications\CustomerPlusUpdate;
use App\Services\Realtime\Notify;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\PlusCycle;
use App\Models\PlusCycleDiscount;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Models\PlusStoreDiscount;
use App\Models\Store;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use App\Support\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI PLUS (2026-10-31) — membership lifecycle. Payment/agreement is
 * off-platform; the admin confirms EVERY cycle.
 *
 * - new:    creates/reactivates the membership with an admin-chosen renewal
 *           policy (monthly | one_time | custom days) and confirms cycle 1.
 * - renew:  confirms the next cycle (starts when the previous one ends);
 *           an approved plan change takes effect here.
 * - change: records next_plan_id — applies from the next cycle.
 * - cancel: stops future renewal; the current cycle keeps its benefits.
 * - custom: recorded and resolved manually by an admin (no automatic effect).
 *
 * Each cycle snapshots its benefits (plan discounts, then per-member
 * approved exceptions/exclusions; free-delivery allowance) — later plan or
 * exception changes never alter a confirmed cycle. Unused free deliveries
 * expire with the cycle.
 */
class PlusService
{
    protected function authorize(Admin $admin, string $permission = 'plus.approve'): void
    {
        if (! AdminPermissions::allows($admin, $permission)) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }

    protected function lockPending(PlusRequest $request, string $type): PlusRequest
    {
        $request = PlusRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        if ($request->status !== PlusRequest::STATUS_PENDING) {
            throw new BusinessRuleException(__('admin.errors.request_not_pending'));
        }
        if ($request->type !== $type) {
            throw new BusinessRuleException(__('admin.errors.wrong_request_type'));
        }

        return $request;
    }

    protected function markResolved(PlusRequest $request, Admin $admin, string $status, ?string $note = null): void
    {
        $request->forceFill([
            'status' => $status,
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note ?? $request->admin_note,
        ])->save();

        if (in_array($status, [PlusRequest::STATUS_APPROVED, PlusRequest::STATUS_REJECTED], true) && $request->user) {
            Notify::send($request->user, new CustomerPlusUpdate($status, $request));
        }
    }

    public function approveNew(PlusRequest $request, Admin $admin, string $policy, ?int $customDays = null, ?CarbonInterface $startsAt = null, ?string $note = null): PlusCycle
    {
        $this->authorize($admin);
        $this->assertPolicy($policy, $customDays);

        return DB::transaction(function () use ($request, $admin, $policy, $customDays, $startsAt, $note) {
            $request = $this->lockPending($request, PlusRequest::TYPE_NEW);
            $plan = PlusPlan::query()->where('is_active', true)->findOrFail($request->requested_plan_id);

            $membership = PlusMembership::query()->where('user_id', $request->user_id)->lockForUpdate()->first();
            if ($membership && $membership->status !== PlusMembership::STATUS_ENDED) {
                throw new BusinessRuleException(__('admin.errors.membership_already_exists'));
            }

            $attributes = [
                'plus_plan_id' => $plan->id,
                'next_plan_id' => null,
                'renewal_policy' => $policy,
                'custom_duration_days' => $policy === PlusMembership::POLICY_CUSTOM ? $customDays : null,
                'status' => PlusMembership::STATUS_ACTIVE,
                'policy_set_by_admin_id' => $admin->id,
                'cancelled_at' => null,
                'ended_at' => null,
            ];
            $membership = $membership
                ? tap($membership)->update($attributes)
                : PlusMembership::create($attributes + ['user_id' => $request->user_id]);

            $request->forceFill(['plus_membership_id' => $membership->id])->save();
            $cycle = $this->createCycle($membership, $plan, $admin, $request, $startsAt ?? now());
            $this->markResolved($request, $admin, PlusRequest::STATUS_APPROVED, $note);

            AdminAudit::log('plus.membership_approved', $membership, ['request_id' => $request->id, 'cycle_id' => $cycle->id, 'policy' => $policy], $admin);

            return $cycle;
        });
    }

    public function approveRenewal(PlusRequest $request, Admin $admin, ?string $note = null): PlusCycle
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = $this->lockPending($request, PlusRequest::TYPE_RENEW);
            $membership = PlusMembership::query()->where('user_id', $request->user_id)->lockForUpdate()->firstOrFail();

            if ($membership->status !== PlusMembership::STATUS_ACTIVE) {
                throw new BusinessRuleException(__('admin.errors.membership_not_active'));
            }

            $plan = PlusPlan::withTrashed()->findOrFail($membership->next_plan_id ?? $membership->plus_plan_id);
            $lastEnd = PlusCycle::query()->where('plus_membership_id', $membership->id)
                ->whereIn('status', [PlusCycle::STATUS_ACTIVE, PlusCycle::STATUS_SCHEDULED])->max('ends_at');
            $startsAt = $lastEnd ? Carbon::parse($lastEnd)->max(now()) : now();

            $cycle = $this->createCycle($membership, $plan, $admin, $request, $startsAt);
            $membership->update(['plus_plan_id' => $plan->id, 'next_plan_id' => null]);
            $request->forceFill(['plus_membership_id' => $membership->id])->save();
            $this->markResolved($request, $admin, PlusRequest::STATUS_APPROVED, $note);

            AdminAudit::log('plus.cycle_confirmed', $membership, ['request_id' => $request->id, 'cycle_id' => $cycle->id], $admin);

            return $cycle;
        });
    }

    public function approveChange(PlusRequest $request, Admin $admin, ?string $note = null): PlusMembership
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = $this->lockPending($request, PlusRequest::TYPE_CHANGE);
            $membership = PlusMembership::query()->where('user_id', $request->user_id)->lockForUpdate()->firstOrFail();
            if ($membership->status !== PlusMembership::STATUS_ACTIVE) {
                throw new BusinessRuleException(__('admin.errors.membership_not_active'));
            }

            $plan = PlusPlan::query()->where('is_active', true)->findOrFail($request->requested_plan_id);
            $membership->update(['next_plan_id' => $plan->id]);
            $this->markResolved($request, $admin, PlusRequest::STATUS_APPROVED, $note);

            AdminAudit::log('plus.plan_change_approved', $membership, ['next_plan_id' => $plan->id], $admin);

            return $membership;
        });
    }

    public function approveCancellation(PlusRequest $request, Admin $admin, ?string $note = null): PlusMembership
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = $this->lockPending($request, PlusRequest::TYPE_CANCEL);
            $membership = PlusMembership::query()->where('user_id', $request->user_id)->lockForUpdate()->firstOrFail();

            $membership->update(['status' => PlusMembership::STATUS_CANCELLED, 'cancelled_at' => now(), 'next_plan_id' => null]);

            PlusCycle::query()->where('plus_membership_id', $membership->id)
                ->where('status', PlusCycle::STATUS_SCHEDULED)
                ->get()->each->update(['status' => PlusCycle::STATUS_CANCELLED]);

            PlusRequest::query()->where('user_id', $membership->user_id)
                ->where('status', PlusRequest::STATUS_PENDING)
                ->whereIn('type', [PlusRequest::TYPE_RENEW, PlusRequest::TYPE_CHANGE])
                ->get()->each(fn (PlusRequest $r) => $this->markResolved($r, $admin, PlusRequest::STATUS_WITHDRAWN, 'membership cancelled'));

            $this->markResolved($request, $admin, PlusRequest::STATUS_APPROVED, $note);
            $this->endIfNothingLeft($membership);

            AdminAudit::log('plus.membership_cancelled', $membership, ['request_id' => $request->id], $admin);

            return $membership->refresh();
        });
    }

    /** `custom` requests are handled manually; approving only records the decision. */
    public function resolveCustom(PlusRequest $request, Admin $admin, string $note): PlusRequest
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = $this->lockPending($request, PlusRequest::TYPE_CUSTOM);
            $this->markResolved($request, $admin, PlusRequest::STATUS_APPROVED, $note);
            AdminAudit::log('plus.custom_request_resolved', $request, [], $admin);

            return $request;
        });
    }

    public function reject(PlusRequest $request, Admin $admin, string $note): PlusRequest
    {
        $this->authorize($admin);

        return DB::transaction(function () use ($request, $admin, $note) {
            $request = PlusRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status !== PlusRequest::STATUS_PENDING) {
                throw new BusinessRuleException(__('admin.errors.request_not_pending'));
            }

            $this->markResolved($request, $admin, PlusRequest::STATUS_REJECTED, $note);
            AdminAudit::log('plus.request_rejected', $request, ['type' => $request->type], $admin);

            return $request;
        });
    }

    public function setRenewalPolicy(PlusMembership $membership, string $policy, ?int $customDays, Admin $admin): PlusMembership
    {
        $this->authorize($admin);
        $this->assertPolicy($policy, $customDays);

        $membership->update([
            'renewal_policy' => $policy,
            'custom_duration_days' => $policy === PlusMembership::POLICY_CUSTOM ? $customDays : null,
            'policy_set_by_admin_id' => $admin->id,
        ]);
        AdminAudit::log('plus.renewal_policy_set', $membership, ['policy' => $policy, 'days' => $customDays], $admin);

        return $membership;
    }

    /** Approved per-member exception on the free-delivery allowance (applies from the next cycle). */
    public function setFreeDeliveriesOverride(PlusMembership $membership, ?int $value, Admin $admin): PlusMembership
    {
        $this->authorize($admin);

        $membership->update([
            'free_deliveries_override' => $value,
            'override_approved_by_admin_id' => $value === null ? null : $admin->id,
        ]);
        AdminAudit::log('plus.free_delivery_override_set', $membership, ['value' => $value], $admin);

        return $membership;
    }

    /** Approved per-member store exception: override discount or exclude the store (next cycle on). */
    public function saveException(PlusMembership $membership, Store $store, array $data, Admin $admin): PlusStoreDiscount
    {
        $this->authorize($admin);

        $excluded = (bool) ($data['is_excluded'] ?? false);
        $exception = PlusStoreDiscount::query()->updateOrCreate(
            ['plus_membership_id' => $membership->id, 'store_id' => $store->id],
            [
                'is_excluded' => $excluded,
                'discount_type' => $excluded ? null : $data['discount_type'],
                'discount_value' => $excluded ? null : $data['discount_value'],
                'max_discount_amount' => $excluded ? null : ($data['max_discount_amount'] ?? null),
                'min_order_amount' => $excluded ? null : ($data['min_order_amount'] ?? null),
                'max_uses_per_cycle' => $excluded ? null : ($data['max_uses_per_cycle'] ?? null),
                'note' => $data['note'] ?? null,
                'is_active' => true,
                'approved_by_admin_id' => $admin->id,
            ]
        );
        AdminAudit::log('plus.exception_saved', $membership, ['store_id' => $store->id, 'excluded' => $excluded], $admin);

        return $exception;
    }

    public function createCycle(PlusMembership $membership, PlusPlan $plan, Admin $admin, ?PlusRequest $request, CarbonInterface $startsAt): PlusCycle
    {
        $currency = Currency::query()->default()->first();
        if (! $currency) {
            throw new BusinessRuleException(__('admin.errors.default_currency_required'));
        }

        $startsAt = Carbon::instance($startsAt);
        $endsAt = match ($membership->renewal_policy) {
            PlusMembership::POLICY_MONTHLY => $startsAt->copy()->addMonthNoOverflow(),
            PlusMembership::POLICY_CUSTOM => $startsAt->copy()->addDays((int) $membership->custom_duration_days),
            default => $startsAt->copy()->addDays($plan->default_duration_days),
        };

        $hasActive = PlusCycle::query()->where('plus_membership_id', $membership->id)->where('status', PlusCycle::STATUS_ACTIVE)->exists();
        $status = (! $hasActive && $startsAt->lte(now())) ? PlusCycle::STATUS_ACTIVE : PlusCycle::STATUS_SCHEDULED;

        $cycle = PlusCycle::create([
            'plus_membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'plus_plan_id' => $plan->id,
            'plus_request_id' => $request?->id,
            'cycle_number' => (int) PlusCycle::query()->where('plus_membership_id', $membership->id)->max('cycle_number') + 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'plan_name' => $plan->getTranslations('name'),
            'renewal_policy' => $membership->renewal_policy,
            'snapshot_currency_id' => $currency->id,
            'free_deliveries_total' => $membership->free_deliveries_override ?? $plan->free_deliveries_per_cycle,
            'confirmed_by_admin_id' => $admin->id,
            'confirmed_at' => now(),
        ]);

        foreach ($this->effectiveDiscounts($membership, $plan) as $storeId => [$source, $row]) {
            PlusCycleDiscount::create([
                'plus_cycle_id' => $cycle->id,
                'store_id' => $storeId,
                'source' => $source,
                'discount_type' => $row->discount_type,
                'snapshot_currency_id' => $currency->id,
                'snapshot_discount_value' => $row->discount_value,
                'snapshot_max_discount_amount' => $row->max_discount_amount,
                'snapshot_min_order_amount' => $row->min_order_amount,
                'max_uses_per_cycle' => $row->max_uses_per_cycle,
                'op_currency_id' => $currency->id,
                'op_discount_value' => $row->discount_value,
                'op_max_discount_amount' => $row->max_discount_amount,
                'op_min_order_amount' => $row->min_order_amount,
            ]);
        }

        return $cycle;
    }

    /** @return array<int, array{0: string, 1: PlusStoreDiscount}> store_id => [source, row] */
    protected function effectiveDiscounts(PlusMembership $membership, PlusPlan $plan): array
    {
        $result = [];
        foreach (PlusStoreDiscount::query()->where('plus_plan_id', $plan->id)->where('is_active', true)->get() as $row) {
            $result[$row->store_id] = ['plan', $row];
        }
        foreach (PlusStoreDiscount::query()->where('plus_membership_id', $membership->id)->where('is_active', true)->get() as $row) {
            if ($row->is_excluded) {
                unset($result[$row->store_id]);
            } else {
                $result[$row->store_id] = ['exception', $row];
            }
        }

        return $result;
    }

    /** Atomic: consumes one free delivery only if the cycle is active and has allowance left. */
    public function consumeFreeDelivery(PlusCycle $cycle): bool
    {
        return PlusCycle::query()
            ->whereKey($cycle->id)
            ->where('status', PlusCycle::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereColumn('free_deliveries_used', '<', 'free_deliveries_total')
            ->increment('free_deliveries_used') === 1;
    }

    /** Gives one free delivery back while the cycle is still active (expired allowances stay expired). */
    public function restoreFreeDelivery(PlusCycle $cycle): bool
    {
        return PlusCycle::query()
            ->whereKey($cycle->id)
            ->where('status', PlusCycle::STATUS_ACTIVE)
            ->where('free_deliveries_used', '>', 0)
            ->decrement('free_deliveries_used') === 1;
    }

    /** Scheduler: expire/activate cycles, end finished memberships, raise renewal requests. */
    public function syncCycles(?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $stats = ['expired' => 0, 'activated' => 0, 'renewal_requests' => 0];

        DB::transaction(function () use ($now, &$stats) {
            PlusCycle::query()->where('status', PlusCycle::STATUS_ACTIVE)->where('ends_at', '<=', $now)->get()
                ->each(function (PlusCycle $c) use (&$stats) {
                    $c->update(['status' => PlusCycle::STATUS_EXPIRED]);
                    $stats['expired']++;
                });

            PlusCycle::query()->where('status', PlusCycle::STATUS_SCHEDULED)->where('starts_at', '<=', $now)->orderBy('starts_at')->get()
                ->each(function (PlusCycle $c) use (&$stats) {
                    $busy = PlusCycle::query()->where('plus_membership_id', $c->plus_membership_id)->where('status', PlusCycle::STATUS_ACTIVE)->exists();
                    if (! $busy) {
                        $c->update(['status' => PlusCycle::STATUS_ACTIVE]);
                        $stats['activated']++;
                    }
                });

            PlusMembership::query()->where('status', PlusMembership::STATUS_CANCELLED)->get()->each(fn ($m) => $this->endIfNothingLeft($m));
        });

        $lead = (int) PlatformSettings::get('plus.renewal_request_lead_days');
        PlusMembership::query()->where('status', PlusMembership::STATUS_ACTIVE)
            ->whereIn('renewal_policy', [PlusMembership::POLICY_MONTHLY, PlusMembership::POLICY_CUSTOM])
            ->with('activeCycle')->get()
            ->each(function (PlusMembership $m) use ($now, $lead, &$stats) {
                $cycle = $m->activeCycle;
                $hasNext = PlusCycle::query()->where('plus_membership_id', $m->id)->where('status', PlusCycle::STATUS_SCHEDULED)->exists();
                $pending = PlusRequest::query()->where('user_id', $m->user_id)->where('type', PlusRequest::TYPE_RENEW)->where('status', PlusRequest::STATUS_PENDING)->exists();

                if ($cycle && ! $hasNext && ! $pending && $cycle->ends_at->lte($now->copy()->addDays($lead))) {
                    PlusRequest::create([
                        'user_id' => $m->user_id,
                        'plus_membership_id' => $m->id,
                        'type' => PlusRequest::TYPE_RENEW,
                        'source' => 'system',
                        'status' => PlusRequest::STATUS_PENDING,
                    ]);
                    $stats['renewal_requests']++;
                }
            });

        return $stats;
    }

    protected function endIfNothingLeft(PlusMembership $membership): void
    {
        $alive = PlusCycle::query()->where('plus_membership_id', $membership->id)
            ->whereIn('status', [PlusCycle::STATUS_ACTIVE, PlusCycle::STATUS_SCHEDULED])->exists();

        if ($membership->status === PlusMembership::STATUS_CANCELLED && ! $alive) {
            $membership->update(['status' => PlusMembership::STATUS_ENDED, 'ended_at' => now()]);
        }
    }

    protected function assertPolicy(string $policy, ?int $customDays): void
    {
        if (! in_array($policy, [PlusMembership::POLICY_MONTHLY, PlusMembership::POLICY_ONE_TIME, PlusMembership::POLICY_CUSTOM], true)) {
            throw new BusinessRuleException(__('admin.errors.invalid_renewal_policy'));
        }
        if ($policy === PlusMembership::POLICY_CUSTOM && (! $customDays || $customDays < 1 || $customDays > 3650)) {
            throw new BusinessRuleException(__('admin.errors.custom_days_required'));
        }
    }
}
