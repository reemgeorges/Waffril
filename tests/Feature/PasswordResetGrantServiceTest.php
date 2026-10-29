<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\PasswordResetGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 3B (2026-10-25).
 */
class PasswordResetGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function service(): PasswordResetGrantService
    {
        return app(PasswordResetGrantService::class);
    }

    public function test_a_freshly_issued_grant_consumes_successfully(): void
    {
        $admin = Admin::factory()->create();
        $token = $this->service()->issue($admin);

        $this->assertTrue($this->service()->consume($admin, $token));
    }

    public function test_a_grant_cannot_be_consumed_twice(): void
    {
        $admin = Admin::factory()->create();
        $token = $this->service()->issue($admin);

        $this->assertTrue($this->service()->consume($admin, $token));
        $this->assertFalse($this->service()->consume($admin, $token));
    }

    public function test_a_grant_is_account_bound_and_cannot_be_used_for_another_account(): void
    {
        $adminA = Admin::factory()->create();
        $adminB = Admin::factory()->create();

        $token = $this->service()->issue($adminA);

        $this->assertFalse($this->service()->consume($adminB, $token));
        $this->assertTrue($this->service()->consume($adminA, $token));
    }

    public function test_a_grant_is_isolated_across_model_types_even_with_the_same_id(): void
    {
        $admin = Admin::factory()->create();
        $user = User::factory()->create(['is_active' => true]);

        $token = $this->service()->issue($admin);

        // Even if $user happened to share the same primary key as $admin,
        // the grant is scoped by (verifiable_type, verifiable_id) together.
        $this->assertFalse($this->service()->consume($user, $token));
    }

    public function test_an_expired_grant_cannot_be_consumed(): void
    {
        config(['otp.reset_grant_ttl_minutes' => 10]);

        $admin = Admin::factory()->create();
        $token = $this->service()->issue($admin);

        Carbon::setTestNow(now()->addMinutes(11));

        $this->assertFalse($this->service()->consume($admin, $token));

        Carbon::setTestNow();
    }

    public function test_issuing_a_new_grant_invalidates_a_prior_unused_one(): void
    {
        $admin = Admin::factory()->create();
        $first = $this->service()->issue($admin);
        $second = $this->service()->issue($admin);

        $this->assertFalse($this->service()->consume($admin, $first));
        $this->assertTrue($this->service()->consume($admin, $second));
    }

    public function test_a_wrong_token_never_consumes_a_real_grant(): void
    {
        $admin = Admin::factory()->create();
        $this->service()->issue($admin);

        $this->assertFalse($this->service()->consume($admin, 'not-the-real-token'));
    }
}
