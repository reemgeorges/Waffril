<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Customer\CustomerAccountService;
use App\Services\Order\OrderAdminService;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — suspension, deletion recovery window and final
 * anonymization (blocked by obligations or ANY non-zero wallet balance).
 */
class CustomerAccountTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function service(): CustomerAccountService
    {
        return app(CustomerAccountService::class);
    }

    public function test_suspension_revokes_tokens_and_is_reversible(): void
    {
        $admin = $this->superAdmin();
        $user = $this->customer();
        $user->createToken('app');

        $this->service()->suspend($user, $admin, 'fraud check');
        $this->assertTrue($user->fresh()->isSuspended());
        $this->assertSame(0, $user->tokens()->count());

        $this->service()->reactivate($user, $admin);
        $this->assertFalse($user->fresh()->isSuspended());
    }

    public function test_anonymization_blocked_during_recovery_and_by_wallet_balance(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());
        $user = $this->customer();
        $this->fundWallet($user, $branch, '5', $admin);

        $this->service()->requestDeletion($user);
        $user = User::withTrashed()->find($user->id);
        $this->assertNotEmpty($this->service()->anonymizationBlockers($user));

        $this->travel(31)->days();
        $blockers = $this->service()->anonymizationBlockers($user->fresh());
        $this->assertSame([__('admin.customers.blockers.wallet_balance')], $blockers);

        $this->expectException(BusinessRuleException::class);
        $this->service()->anonymize($user->fresh(), $admin);
    }

    public function test_anonymization_removes_pii_keeps_financial_history_and_frees_the_phone(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());
        $user = $this->customer();
        $phone = $user->phone;
        $order = $this->order($user, $branch, '10', ['status' => OrderStatus::Delivered]);

        $this->service()->requestDeletion($user);
        $this->travel(31)->days();
        $this->service()->anonymize(User::withTrashed()->find($user->id), $admin);

        $anonymized = User::withTrashed()->find($user->id);
        $this->assertNotNull($anonymized->anonymized_at);
        $this->assertNull($anonymized->phone);
        $this->assertNull($anonymized->first_name);
        $this->assertSame(['redacted' => true], $order->fresh()->delivery_address);
        $this->assertSame('10.0000', (string) $order->fresh()->total);
        $this->assertSame(1, $order->payments()->count());

        $newcomer = User::factory()->create(['phone' => $phone]);
        $this->assertSame($phone, $newcomer->phone);
    }

    public function test_open_orders_block_anonymization(): void
    {
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city());
        $user = $this->customer();
        $order = $this->order($user, $branch, '10');
        app(OrderAdminService::class)->accept($order, $admin);

        $this->service()->requestDeletion($user);
        $this->travel(31)->days();

        $this->assertContains(__('admin.customers.blockers.open_orders'), $this->service()->anonymizationBlockers(User::withTrashed()->find($user->id)));
    }
}
