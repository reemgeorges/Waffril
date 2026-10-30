<?php

namespace App\Http\Controllers\Customer;

use App\Events\Plus\PlusRequestSubmitted;
use App\Exceptions\BusinessRuleException;
use App\Models\PlusCycle;
use App\Models\PlusMembership;
use App\Models\PlusPlan;
use App\Models\PlusRequest;
use App\Services\Customer\CustomerCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI customer API (2026-11-03) — WAFFRLI PLUS for customers: plans,
 * membership with the ACTIVE CYCLE SNAPSHOT (benefits as approved), free
 * deliveries left, requests. Every cycle needs admin approval; payment of
 * the subscription happens outside the app.
 */
class CustomerPlusController extends CustomerController
{
    public function plans()
    {
        return $this->respond(fn () => ['plans' => PlusPlan::query()->where('is_active', true)->with('storeDiscounts.store')->orderBy('sort_order')->get()->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description,
            'duration_days' => $p->default_duration_days, 'free_deliveries_per_cycle' => $p->free_deliveries_per_cycle,
            'display_price' => $p->display_price !== null ? (string) $p->display_price : null,
            'store_benefits' => $p->storeDiscounts->where('is_active', true)->map(fn ($d) => [
                'store' => ['id' => $d->store->id, 'name' => $d->store->name], 'discount_type' => $d->discount_type,
                'discount_value' => (string) $d->discount_value, 'max_discount' => $d->max_discount_amount !== null ? (string) $d->max_discount_amount : null,
                'min_order' => $d->min_order_amount !== null ? (string) $d->min_order_amount : null,
            ])->values(),
        ])->values()]);
    }

    public function membership(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = $this->customer($request);
            $m = PlusMembership::query()->where('user_id', $user->id)->with(['plan', 'nextPlan'])->first();
            if (! $m) {
                return ['membership' => null];
            }
            $cycles = PlusCycle::query()->where('plus_membership_id', $m->id)->with('discounts.store')->orderBy('cycle_number')->get();
            $cycle = fn ($c) => $c ? [
                'id' => $c->id, 'number' => $c->cycle_number, 'status' => $c->status, 'plan_name' => $c->plan_name,
                'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(),
                'free_deliveries_total' => $c->free_deliveries_total, 'free_deliveries_used' => $c->free_deliveries_used,
                'free_deliveries_remaining' => max(0, $c->free_deliveries_total - $c->free_deliveries_used),
                'store_discounts' => $c->discounts->map(fn ($d) => [
                    'store' => ['id' => $d->store->id, 'name' => $d->store->name], 'discount_type' => $d->discount_type,
                    'discount_value' => (string) $d->snapshot_discount_value, 'max_discount' => $d->snapshot_max_discount_amount !== null ? (string) $d->snapshot_max_discount_amount : null,
                    'min_order' => $d->snapshot_min_order_amount !== null ? (string) $d->snapshot_min_order_amount : null,
                ])->values(),
            ] : null;

            return ['membership' => [
                'status' => $m->status, 'plan' => ['id' => $m->plan?->id, 'name' => $m->plan?->name],
                'next_plan' => $m->nextPlan ? ['id' => $m->nextPlan->id, 'name' => $m->nextPlan->name] : null,
                'renewal_policy' => $m->renewal_policy, 'custom_duration_days' => $m->custom_duration_days,
                'cancelled_at' => $m->cancelled_at?->toIso8601String(),
                'current_cycle' => $cycle($cycles->firstWhere('status', PlusCycle::STATUS_ACTIVE)),
                'next_cycle' => $cycle($cycles->firstWhere('status', PlusCycle::STATUS_SCHEDULED)),
                'past_cycles' => $cycles->whereIn('status', [PlusCycle::STATUS_EXPIRED, PlusCycle::STATUS_CANCELLED])->map(fn ($c) => ['number' => $c->cycle_number, 'status' => $c->status, 'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String()])->values(),
            ]];
        });
    }

    /** The discount the active cycle gives at a branch's store (if any), and free deliveries left. */
    public function eligibleDiscounts(Request $request)
    {
        return $this->respond(function () use ($request) {
            $user = $this->customer($request);
            $cycle = PlusCycle::query()->where('user_id', $user->id)->where('status', PlusCycle::STATUS_ACTIVE)->where('ends_at', '>', now())->with('discounts.store')->first();
            $branch = $request->query('branch_id') ? app(CustomerCatalogService::class)->branchInCity($user, $request->query('branch_id')) : null;
            $discounts = $cycle ? $cycle->discounts->when($branch, fn ($c) => $c->where('store_id', $branch->store_id)) : collect();

            return [
                'has_active_cycle' => (bool) $cycle,
                'free_deliveries_remaining' => $cycle ? max(0, $cycle->free_deliveries_total - $cycle->free_deliveries_used) : 0,
                'discounts' => $discounts->map(fn ($d) => ['store' => ['id' => $d->store->id, 'name' => $d->store->name], 'discount_type' => $d->discount_type, 'discount_value' => (string) $d->op_discount_value, 'max_discount' => $d->op_max_discount_amount !== null ? (string) $d->op_max_discount_amount : null, 'min_order' => $d->op_min_order_amount !== null ? (string) $d->op_min_order_amount : null])->values(),
                'note' => 'PLUS store discounts replace public offers on the same order; free delivery can be combined.',
            ];
        });
    }

    public function requests(Request $request)
    {
        return $this->respond(fn () => ['requests' => PlusRequest::query()->where('user_id', $this->customer($request)->id)->with('requestedPlan')->orderByDesc('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'source' => $r->source,
            'plan' => $r->requestedPlan ? ['id' => $r->requestedPlan->id, 'name' => $r->requestedPlan->name] : null,
            'note' => $r->customer_note, 'admin_note' => $r->admin_note,
            'created_at' => $r->created_at?->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ])->values()]);
    }

    public function submit(Request $request)
    {
        return $this->respond(function () use ($request) {
            $data = $request->validate([
                'type' => ['required', 'in:new,renew,change,cancel,custom'],
                'plan_id' => ['nullable', 'integer'],
                'note' => ['nullable', 'required_if:type,custom', 'string', 'max:500'],
            ]);
            $user = $this->customer($request);
            $membership = PlusMembership::query()->where('user_id', $user->id)->first();
            $active = $membership && $membership->status === PlusMembership::STATUS_ACTIVE;
            $plan = isset($data['plan_id']) ? PlusPlan::query()->whereKey($data['plan_id'])->where('is_active', true)->first() : null;

            $valid = match ($data['type']) {
                'new' => ! $active && $plan !== null,
                'renew', 'cancel' => $active,
                'change' => $active && $plan !== null && $plan->id !== $membership->plus_plan_id,
                'custom' => true,
            };
            if (! $valid) {
                throw new BusinessRuleException(__('messages.customer.plus_request_invalid'), 422);
            }

            try {
                $plusRequest = DB::transaction(fn () => PlusRequest::create([
                    'user_id' => $user->id, 'plus_membership_id' => $membership?->id, 'type' => $data['type'],
                    'requested_plan_id' => in_array($data['type'], ['new', 'change'], true) ? $plan->id : null,
                    'customer_note' => $data['note'] ?? null, 'source' => 'customer',
                ]));
            } catch (QueryException) {
                throw new BusinessRuleException(__('messages.customer.plus_request_pending_exists'), 409);
            }
            DB::afterCommit(fn () => event(new PlusRequestSubmitted($plusRequest->id)));

            return ['message' => __('messages.customer.plus_request_sent'), 'request' => ['id' => $plusRequest->id, 'type' => $plusRequest->type, 'status' => $plusRequest->fresh()->status]];
        });
    }
}
