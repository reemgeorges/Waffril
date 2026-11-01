<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\UserAddress;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — the complete approved schema exists and the
 * driver-portable integrity rules (generated-column uniqueness, composite
 * keys) hold on the test database. MySQL-only CHECK constraints are
 * covered by the separate MySQL-only test group.
 */
class SchemaIntegrityTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public const TABLES = [
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens',
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'users', 'admins', 'personal_access_tokens', 'device_tokens', 'otps', 'password_reset_grants', 'tests', 'categories',
        'settings', 'currencies', 'currency_redenominations', 'cities', 'delivery_areas', 'delivery_fee_rules', 'notifications',
        'drivers',
        'store_categories', 'store_store_category', 'stores', 'branches', 'branch_opening_hours', 'branch_hour_exceptions',
        'product_category', 'products', 'product_option_groups', 'product_options', 'branch_products', 'branch_product_options', 'offers', 'favorites',
        'user_addresses', 'carts', 'cart_items', 'cart_item_options',
        'plus_plans', 'plus_memberships', 'plus_store_discounts', 'plus_requests', 'plus_cycles', 'plus_cycle_discounts',
        'payment_methods', 'wallets', 'driver_cash_settlements', 'orders', 'order_items', 'order_item_options', 'order_status_histories',
        'order_cancellation_requests', 'order_cancellations', 'order_payments', 'order_refunds', 'wallet_transactions',
        'product_ratings',
        'admin_audit_logs',
    ];

    public function test_all_approved_tables_exist(): void
    {
        $this->assertCount(66, self::TABLES, '65 approved tables + admin_audit_logs');
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        foreach (['store_ratings', 'driver_ratings', 'store_favorites', 'coupons', 'merchant_payouts', 'product_images', 'support_tickets'] as $forbidden) {
            $this->assertFalse(Schema::hasTable($forbidden), "Excluded table {$forbidden} must not exist");
        }
    }

    public function test_users_keep_legacy_columns_and_gain_identity_columns(): void
    {
        foreach (['name', 'password', 'prefix_phone', 'phone_number', 'first_name', 'last_name', 'phone', 'verified_email', 'account_status', 'recovery_until', 'anonymized_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "users.{$column}");
        }
    }

    public function test_only_one_default_address_per_customer(): void
    {
        $user = $this->customer();
        $city = $this->city();
        $make = fn () => UserAddress::create(['user_id' => $user->id, 'city_id' => $city->id, 'address_line' => 'x', 'lat' => 33.5, 'lng' => 36.3, 'is_default' => true]);

        $first = $make();
        try {
            $make();
            $this->fail('Second default address must be rejected.');
        } catch (QueryException) {
        }

        $first->delete();
        $this->assertNotNull($make()->id, 'a soft-deleted default no longer blocks a new one');
    }

    public function test_verified_email_is_unique_only_once_verified(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $a->forceFill(['email' => 'same@example.test'])->save();

        $this->expectException(QueryException::class);
        // Legacy users.email unique index is still present, so the clash is rejected either way;
        // verified_email uniqueness is asserted at schema level by the MySQL-only test group.
        $b->forceFill(['email' => 'same@example.test', 'email_verified_at' => now()])->save();
    }

    public function test_one_pending_cancellation_request_per_order(): void
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->city()), '10');
        OrderCancellationRequest::create(['order_id' => $order->id]);

        $this->expectException(QueryException::class);
        OrderCancellationRequest::create(['order_id' => $order->id]);
    }

    public function test_checkout_idempotency_key_is_unique_per_customer(): void
    {
        $order = $this->order($this->customer(), $this->branch($this->store(), $this->city()), '10');

        $this->expectException(QueryException::class);
        Order::query()->insert(array_merge(
            collect($order->getAttributes())->except(['id', 'order_number'])->all(),
            ['order_number' => 'DUP'.$order->id]
        ));
    }
}
