<?php

namespace App\Services\Customer;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderPayment;
use App\Models\PlusMembership;
use App\Models\PlusRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WAFFRLI (2026-10-31) — customer account state.
 *
 * - suspend / reactivate: admin only (`customers.suspend`); suspension
 *   revokes all API tokens and device tokens.
 * - requestDeletion: customer flow (future API) — soft delete with a
 *   30-day recovery window.
 * - anonymize: final, irreversible PII removal. Refused while ANY blocker
 *   remains (recovery window, open orders, pending cash, open cancellation
 *   requests, or a NON-ZERO wallet balance). There is deliberately no
 *   mechanism here that clears, converts or confiscates wallet funds: how a
 *   remaining balance reaches zero is an unresolved business policy.
 *   Financial records (orders, payments, refunds, ledger) are kept.
 */
class CustomerAccountService
{
    public const RECOVERY_DAYS = 30;

    public function suspend(User $user, Admin $admin, string $reason): User
    {
        $this->authorize($admin, 'customers.suspend');

        return DB::transaction(function () use ($user, $admin, $reason) {
            $user = User::withTrashed()->lockForUpdate()->findOrFail($user->getKey());
            if ($user->anonymized_at) {
                throw new BusinessRuleException(__('admin.errors.customer_anonymized'));
            }

            $user->forceFill([
                'account_status' => User::ACCOUNT_SUSPENDED,
                'suspended_at' => now(),
                'suspended_by_admin_id' => $admin->id,
                'suspension_reason' => $reason,
            ])->save();

            $this->revokeAccess($user);
            AdminAudit::log('customer.suspended', $user, ['reason' => $reason], $admin);

            return $user;
        });
    }

    public function reactivate(User $user, Admin $admin): User
    {
        $this->authorize($admin, 'customers.suspend');

        return DB::transaction(function () use ($user, $admin) {
            $user = User::withTrashed()->lockForUpdate()->findOrFail($user->getKey());
            if ($user->anonymized_at) {
                throw new BusinessRuleException(__('admin.errors.customer_anonymized'));
            }

            $user->forceFill([
                'account_status' => User::ACCOUNT_ACTIVE,
                'suspended_at' => null,
                'suspended_by_admin_id' => null,
                'suspension_reason' => null,
            ])->save();

            AdminAudit::log('customer.reactivated', $user, [], $admin);

            return $user;
        });
    }

    /** Customer-initiated deletion (future customer API). */
    public function requestDeletion(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $user->forceFill(['recovery_until' => now()->addDays(self::RECOVERY_DAYS)])->save();
            $user->carts()->delete();
            $this->revokeAccess($user);
            $user->delete();

            return $user;
        });
    }

    /** @return list<string> translated reasons; empty when anonymization may proceed. */
    public function anonymizationBlockers(User $user): array
    {
        $blockers = [];

        if (! $user->trashed()) {
            $blockers[] = __('admin.customers.blockers.not_deleted');
        }
        if ($user->anonymized_at) {
            $blockers[] = __('admin.customers.blockers.already_anonymized');
        }
        if ($user->recovery_until && $user->recovery_until->isFuture()) {
            $blockers[] = __('admin.customers.blockers.recovery_window', ['until' => $user->recovery_until->toDateTimeString()]);
        }
        if (Order::query()->where('user_id', $user->id)->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->exists()) {
            $blockers[] = __('admin.customers.blockers.open_orders');
        }
        if (OrderPayment::query()->whereHas('order', fn ($q) => $q->where('user_id', $user->id))->where('status', OrderPayment::STATUS_PENDING)->exists()) {
            $blockers[] = __('admin.customers.blockers.pending_payments');
        }
        if (OrderCancellationRequest::query()->whereHas('order', fn ($q) => $q->where('user_id', $user->id))->where('status', OrderCancellationRequest::STATUS_PENDING)->exists()) {
            $blockers[] = __('admin.customers.blockers.open_cancellation_requests');
        }
        if (Wallet::query()->where('user_id', $user->id)->where('balance', '>', 0)->exists()) {
            $blockers[] = __('admin.customers.blockers.wallet_balance');
        }

        return $blockers;
    }

    public function anonymize(User $user, Admin $admin): User
    {
        $this->authorize($admin, 'customers.anonymize');

        return DB::transaction(function () use ($user, $admin) {
            $user = User::withTrashed()->lockForUpdate()->findOrFail($user->getKey());
            $blockers = $this->anonymizationBlockers($user);
            if ($blockers !== []) {
                throw new BusinessRuleException(implode(' ', $blockers));
            }

            $user->addresses()->withTrashed()->get()->each->forceDelete();
            $user->favoriteProducts()->detach();
            $user->carts()->delete();
            $this->revokeAccess($user);

            PlusRequest::query()->where('user_id', $user->id)->where('status', PlusRequest::STATUS_PENDING)
                ->update(['status' => PlusRequest::STATUS_WITHDRAWN, 'reviewed_at' => now()]);
            PlusMembership::query()->where('user_id', $user->id)->where('status', '!=', PlusMembership::STATUS_ENDED)
                ->update(['status' => PlusMembership::STATUS_ENDED, 'ended_at' => now()]);

            Order::query()->where('user_id', $user->id)->update([
                'delivery_address' => json_encode(['redacted' => true]),
                'delivery_lat' => null,
                'delivery_lng' => null,
                'customer_notes' => null,
            ]);

            $user->forceFill([
                'name' => 'Deleted customer',
                'first_name' => null,
                'last_name' => null,
                'email' => "deleted-{$user->id}@anonymized.invalid",
                'email_verified_at' => null,
                'phone' => null,
                'prefix_phone' => null,
                'phone_number' => null,
                'social_id' => null,
                'password' => Str::random(64),
                'anonymized_at' => now(),
            ])->save();

            AdminAudit::log('customer.anonymized', $user, [], $admin);

            return $user;
        });
    }

    protected function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        DeviceToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
            ->update(['is_active' => false, 'revoked_at' => now()]);
    }

    protected function authorize(Admin $admin, string $permission): void
    {
        if (! AdminPermissions::allows($admin, $permission)) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }
}
