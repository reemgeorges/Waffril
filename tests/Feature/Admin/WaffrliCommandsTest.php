<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Order;
use Illuminate\Support\Facades\Hash;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — operator console commands.
 */
class WaffrliCommandsTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_create_super_admin_takes_typed_credentials_and_hashes_them(): void
    {
        $this->artisan('waffrli:create-super-admin', ['--email' => 'owner@example.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (min 10 characters)', 'a-long-secret-1')
            ->assertSuccessful();

        $admin = Admin::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNotSame('a-long-secret-1', $admin->password);
        $this->assertTrue(Hash::check('a-long-secret-1', $admin->password));
    }

    public function test_create_super_admin_rejects_a_short_password(): void
    {
        $this->artisan('waffrli:create-super-admin', ['--email' => 'owner@example.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (min 10 characters)', 'short')
            ->assertFailed();

        $this->assertFalse(Admin::where('email', 'owner@example.test')->exists());
    }

    public function test_flag_urgent_orders_never_cancels(): void
    {
        $branch = $this->branch($this->store(), $this->city());
        $late = $this->order($this->customer(), $branch, '10', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->subMinutes(5)]);
        $future = $this->order($this->customer(), $branch, '10', ['type' => Order::TYPE_SCHEDULED, 'scheduled_for' => now()->addHour()]);

        $this->artisan('waffrli:flag-urgent-orders')->assertSuccessful();

        $this->assertNotNull($late->fresh()->urgent_at);
        $this->assertSame('pending', $late->fresh()->status->value);
        $this->assertNull($future->fresh()->urgent_at);
    }
}
