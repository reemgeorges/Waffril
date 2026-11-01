<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\Audit\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WAFFRLI demo data (2026-11-01) — shared state and safety rules for the
 * demo seeders.
 *
 * - Demo data is identifiable: emails end with DEMO_EMAIL_DOMAIN (a
 *   reserved .test domain), phones use the fictional +1 202 555 01xx/02xx
 *   range, store slugs start with "demo-", order numbers with "DEMO-".
 * - Business actions run as a temporary "demo data generator" admin whose
 *   password is random and never shown; it gets the super-admin role only
 *   while seeding and is deactivated (role removed) at the end. Every audit
 *   entry written meanwhile carries generated_demo = true.
 * - Time travel (Carbon::setTestNow) back-dates realistic history; it is
 *   always reset.
 */
class DemoContext
{
    public const DEMO_EMAIL_DOMAIN = 'demo.waffrli.test';

    public const OPERATOR_EMAIL = 'demo-data-generator@'.self::DEMO_EMAIL_DOMAIN;

    public const ALLOWED_ENVIRONMENTS = ['local', 'development', 'demo', 'testing'];

    protected ?Admin $operator = null;

    protected ?Carbon $anchor = null;

    /** Refuses production/staging and databases holding real orders or wallet movements. */
    public static function assertSafeToSeed(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException('WAFFRLI demo data may only be seeded in: '.implode(', ', self::ALLOWED_ENVIRONMENTS).'. Current environment: '.app()->environment().'.');
        }

        if (Order::query()->where('order_number', 'not like', 'DEMO-%')->exists()) {
            throw new RuntimeException('This database contains non-demo orders. Demo data is only seeded into an empty local/demo database.');
        }

        $realWalletMovement = WalletTransaction::query()
            ->whereHas('wallet.user', fn ($query) => $query->where('email', 'not like', '%@'.self::DEMO_EMAIL_DOMAIN))
            ->exists();
        if ($realWalletMovement) {
            throw new RuntimeException('This database contains non-demo wallet movements. Demo data was not seeded.');
        }
    }

    /** The moment all relative demo dates are computed from (fixed for one run). */
    public function anchor(): Carbon
    {
        return ($this->anchor ??= Carbon::now()->startOfMinute())->copy();
    }

    public function operator(): Admin
    {
        if ($this->operator) {
            return $this->operator;
        }

        $admin = Admin::withTrashed()->firstOrNew(['email' => self::OPERATOR_EMAIL]);
        $admin->forceFill([
            'name' => 'Demo data generator (system)',
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
        ])->save();
        if ($admin->trashed()) {
            $admin->restore();
        }
        $admin->assignRole(Admin::SUPER_ADMIN_ROLE);

        return $this->operator = $admin->fresh();
    }

    /** Removes the operator's privileges and disables it; it can never log in. */
    public function retireOperator(): void
    {
        $admin = $this->operator ?? Admin::query()->where('email', self::OPERATOR_EMAIL)->first();
        if ($admin) {
            $admin->removeRole(Admin::SUPER_ADMIN_ROLE);
            $admin->forceFill(['is_active' => false, 'password' => Hash::make(Str::random(64))])->save();
        }
        $this->operator = null;
    }

    /** Runs $callback with now() at $moment and demo audit context, always restoring the clock. */
    public function at(Carbon $moment, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return AdminAudit::withContext(['generated_demo' => true], $callback);
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    public static function email(string $local): string
    {
        return $local.'@'.self::DEMO_EMAIL_DOMAIN;
    }

    /** Fictional North-American 555-01xx / 555-02xx numbers (reserved for fiction). */
    public static function phone(int $n): string
    {
        return '+1202555'.str_pad((string) (100 + $n), 4, '0', STR_PAD_LEFT);
    }

    /** A password nobody knows: demo accounts are not meant to be logged into. */
    public static function unusablePassword(): string
    {
        return Hash::make(Str::random(64));
    }
}
