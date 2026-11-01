<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\OrderCancellation;
use App\Models\OrderPayment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Policies\WalletPolicy;
use App\Policies\WalletTransactionPolicy;
use App\Services\Order\OrderAdminService;
use App\Services\Order\RefundService;
use App\Services\Wallet\WalletLedger;
use LogicException;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — wallet ledger, cancellation reversals and refund
 * caps, including cancellation after pickup.
 */
class WalletRefundCancellationTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function setUpWorld(): array
    {
        $admin = $this->superAdmin();
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $user = $this->customer();
        $this->fundWallet($user, $branch, '50', $admin);

        return [$admin, $branch, $user];
    }

    protected function wallet($user): Wallet
    {
        return Wallet::where('user_id', $user->id)->firstOrFail();
    }

    public function test_wallet_is_funded_only_by_refund_and_ledger_stays_consistent(): void
    {
        [, , $user] = $this->setUpWorld();
        $wallet = $this->wallet($user);

        $this->assertSame('50.0000', (string) $wallet->balance);
        $this->assertSame(['order_refund'], WalletTransaction::where('wallet_id', $wallet->id)->pluck('type')->all());
        $this->assertTrue(app(WalletLedger::class)->isConsistent($wallet));
    }

    public function test_wallet_debit_on_submission_and_full_reversal_exactly_once_before_pickup(): void
    {
        [$admin, $branch, $user] = $this->setUpWorld();
        $order = $this->order($user, $branch, '30', ['wallet' => '20']);
        $this->assertSame('30.0000', (string) $this->wallet($user)->fresh()->balance);

        $service = app(OrderAdminService::class);
        $first = $service->cancelByAdmin($order, $admin, ['reason_code' => 'other', 'idempotency_key' => 'k1']);
        $replay = $service->cancelByAdmin($order, $admin, ['reason_code' => 'other', 'idempotency_key' => 'k1']);

        $this->assertTrue($first->is($replay));
        $this->assertSame('20.0000', (string) $first->wallet_reversed_amount);
        $this->assertSame('50.0000', (string) $this->wallet($user)->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('type', 'order_payment_reversal')->count());
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('cancelled', OrderPayment::where('order_id', $order->id)->whereHas('method', fn ($q) => $q->where('kind', 'cash'))->value('status'));

        $this->expectException(BusinessRuleException::class);
        $service->cancelByAdmin($order, $admin, ['reason_code' => 'other', 'idempotency_key' => 'k2']);
    }

    public function test_refunds_are_capped_by_collected_amount_partial_and_idempotent(): void
    {
        [$admin, $branch, $user] = $this->setUpWorld();
        $order = $this->order($user, $branch, '40', ['status' => OrderStatus::Delivered]);
        $payment = $order->payments()->first();
        $refunds = app(RefundService::class);

        $a = $refunds->refund($payment, '15', 'quality', null, $admin, 'r1');
        $this->assertTrue($a->is($refunds->refund($payment, '15', 'quality', null, $admin, 'r1')));
        $refunds->refund($payment, '25', 'quality', null, $admin, 'r2');

        $this->assertSame('40.0000', (string) $payment->fresh()->refunded_amount);
        $this->assertSame('90.0000', (string) $this->wallet($user)->fresh()->balance);

        try {
            $refunds->refund($payment, '0.0001', 'quality', null, $admin, 'r3');
            $this->fail('Refund above the collected amount must be refused.');
        } catch (BusinessRuleException) {
            $this->assertSame('40.0000', (string) $payment->fresh()->refunded_amount);
        }
    }

    public function test_refund_refused_for_undelivered_order_and_without_permission(): void
    {
        [$admin, $branch, $user] = $this->setUpWorld();
        $pending = $this->order($user, $branch, '10');

        try {
            app(RefundService::class)->refund($pending->payments()->first(), '5', 'x', null, $admin, 'p1');
            $this->fail('Uncollected cash must not be refundable.');
        } catch (BusinessRuleException) {
        }

        $delivered = $this->order($user, $branch, '10', ['status' => OrderStatus::Delivered]);
        $this->expectException(BusinessRuleException::class);
        app(RefundService::class)->refund($delivered->payments()->first(), '5', 'x', null, $this->employee(['orders.view']), 'p2');
    }

    public function test_cancellation_after_pickup_needs_permission_handling_and_respects_caps(): void
    {
        [$admin, $branch, $user] = $this->setUpWorld();
        $order = $this->order($user, $branch, '30', ['wallet' => '20', 'status' => OrderStatus::PickedUp]);
        $service = app(OrderAdminService::class);

        $plain = $this->employee(['orders.cancel', 'refunds.create']);
        try {
            $service->cancelByAdmin($order, $plain, ['reason_code' => 'customer_refused', 'driver_handling' => 'returned_to_branch']);
            $this->fail('Cancelling after pickup requires orders.cancel_after_pickup.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->getStatus());
        }

        try {
            $service->cancelByAdmin($order, $admin, ['reason_code' => 'customer_refused']);
            $this->fail('Driver handling is required after pickup.');
        } catch (BusinessRuleException) {
        }

        try {
            $service->cancelByAdmin($order, $admin, ['reason_code' => 'customer_refused', 'driver_handling' => 'returned_to_branch', 'wallet_reversal_amount' => '25']);
            $this->fail('Wallet reversal above the reversible amount must be refused.');
        } catch (BusinessRuleException) {
        }
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()->status);

        $cancellation = $service->cancelByAdmin($order, $admin, [
            'reason_code' => 'customer_refused', 'note' => 'refused at door', 'driver_handling' => 'returned_to_branch',
            'wallet_reversal_amount' => '5', 'cash_collected_amount' => '10', 'refund_collected_cash_amount' => '4',
            'idempotency_key' => 'pickup-1',
        ]);

        $this->assertSame('picked_up', $cancellation->from_status);
        $this->assertSame('5.0000', (string) $cancellation->wallet_reversed_amount);
        $this->assertSame('10.0000', (string) $cancellation->cash_collected_amount);
        $this->assertSame(1, $cancellation->refunds()->count());
        // 50 funded − 20 paid + 5 reversed + 4 cash refunded = 39
        $this->assertSame('39.0000', (string) $this->wallet($user)->fresh()->balance);

        $cash = OrderPayment::where('order_id', $order->id)->whereHas('method', fn ($q) => $q->where('kind', 'cash'))->first();
        $this->assertSame('10.0000', (string) $cash->collected_amount);
        $this->assertSame('4.0000', (string) $cash->refunded_amount);
        $this->assertTrue(app(WalletLedger::class)->isConsistent($this->wallet($user)));
    }

    public function test_ledger_rows_and_cancellations_are_append_only(): void
    {
        [, , $user] = $this->setUpWorld();
        $tx = WalletTransaction::firstOrFail();

        $this->expectException(LogicException::class);
        $tx->update(['amount' => '999']);
    }

    public function test_cancellation_records_cannot_be_deleted(): void
    {
        [$admin, $branch, $user] = $this->setUpWorld();
        $order = $this->order($user, $branch, '10');
        $cancellation = app(OrderAdminService::class)->cancelByAdmin($order, $admin, ['reason_code' => 'other']);

        $this->expectException(LogicException::class);
        OrderCancellation::findOrFail($cancellation->id)->delete();
    }

    public function test_no_one_may_edit_wallets_or_the_ledger(): void
    {
        $super = $this->superAdmin();

        $this->assertFalse((new WalletPolicy)->update($super));
        $this->assertFalse((new WalletPolicy)->create($super));
        $this->assertFalse((new WalletTransactionPolicy)->create($super));
        $this->assertFalse((new WalletTransactionPolicy)->delete($super));
        $this->assertTrue((new WalletPolicy)->viewAny($super));
    }
}
