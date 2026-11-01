<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PlusRequest;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI MySQL verification (2026-10-31) — proves on a real MySQL 8 server
 * that every CHECK constraint, conditional-unique marker, composite FK and
 * RESTRICT FK of the WAFFRLI schema actually rejects invalid data.
 *
 * Skipped (NOT RUN) on any other driver. Run against an ISOLATED database:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=waffrli_test ... vendor/bin/phpunit --group mysql
 */
#[Group('mysql')]
class WaffrliMySqlSchemaTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT RUN: requires an isolated MySQL 8 test database (current driver: '.DB::connection()->getDriverName().').');
        }
    }

    /** Asserts MySQL rejects the statement with the given error code (and constraint name, when given). */
    protected function assertRejected(int $code, callable $statement, ?string $constraint = null, ?string $label = null): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('Expected MySQL to reject the statement'.($constraint ?? $label ? ' ('.($constraint ?? $label).')' : '').'.');
        } catch (QueryException $e) {
            $this->assertSame($code, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            if ($constraint !== null) {
                $this->assertStringContainsString("'{$constraint}'", $e->getMessage());
            }
        }
    }

    public function test_every_check_constraint_rejects_a_violating_update(): void
    {
        $data = $this->seedFullDataset();
        $raw = fn (string $expression) => DB::raw($expression);
        $nonDefaultCurrency = ['id' => $data['syp']->id];

        // constraint => [table, set, optional where]. Each update violates exactly that constraint.
        $violations = [
            'chk_bhe_kind' => ['branch_hour_exceptions', ['kind' => 'other']],
            'chk_bhe_window' => ['branch_hour_exceptions', ['ends_at' => $raw('starts_at')]],
            'chk_boh_day' => ['branch_opening_hours', ['day_of_week' => 7]],
            'chk_boh_times' => ['branch_opening_hours', ['closes_at' => $raw('opens_at')]],
            'chk_bpo_price_delta' => ['branch_product_options', ['price_delta' => -1]],
            'chk_bp_price' => ['branch_products', ['price' => -1]],
            'chk_branches_coordinates' => ['branches', ['lat' => 91]],
            'chk_branches_min_order' => ['branches', ['min_order_amount' => -1]],
            'chk_cio_quantity' => ['cart_item_options', ['quantity' => 0]],
            'chk_cart_items_quantity' => ['cart_items', ['quantity' => 100]],
            'chk_cities_lat_range' => ['cities', ['center_lat' => 91]],
            'chk_cities_lng_range' => ['cities', ['center_lng' => 181]],
            'chk_cities_radius_positive' => ['cities', ['radius_km' => 0]],
            'chk_currencies_code_format' => ['currencies', ['code' => 'us1'], $nonDefaultCurrency],
            'chk_currencies_decimals_range' => ['currencies', ['decimals' => 5], $nonDefaultCurrency],
            'chk_currencies_default_consistent' => ['currencies', ['exchange_rate' => 2], ['id' => $data['usd']->id]],
            'chk_currencies_rate_positive' => ['currencies', ['exchange_rate' => 0], $nonDefaultCurrency],
            'chk_cur_redenom_distinct_currencies' => ['currency_redenominations', ['to_currency_id' => $raw('from_currency_id')]],
            'chk_cur_redenom_rate_positive' => ['currency_redenominations', ['conversion_rate' => 0]],
            'chk_cur_redenom_status' => ['currency_redenominations', ['status' => 'bogus']],
            'chk_delivery_areas_bbox' => ['delivery_areas', ['min_lat' => $raw('max_lat + 1')]],
            'chk_delivery_fee_rules_area_needs_city' => ['delivery_fee_rules', ['city_id' => null], ['delivery_area_id' => $data['areaId']]],
            'chk_delivery_fee_rules_band' => ['delivery_fee_rules', ['distance_to_km' => 0]],
            'chk_delivery_fee_rules_fees' => ['delivery_fee_rules', ['base_fee' => -1]],
            'chk_dcs_amounts' => ['driver_cash_settlements', ['expected_amount' => -1]],
            'chk_dcs_status' => ['driver_cash_settlements', ['status' => 'lost']],
            'chk_drivers_availability' => ['drivers', ['availability' => 'busy']],
            'chk_drivers_language' => ['drivers', ['language' => 'fr']],
            'chk_drivers_phone_e164' => ['drivers', ['phone' => '0912345678']],
            'chk_offers_type' => ['offers', ['discount_type' => 'bogo']],
            'chk_offers_value' => ['offers', ['discount_value' => 101]],
            'chk_offers_window' => ['offers', ['ends_at' => $raw('starts_at')]],
            'chk_ocr_status' => ['order_cancellation_requests', ['status' => 'maybe']],
            'chk_oc_admin_required' => ['order_cancellations', ['admin_id' => null]],
            'chk_oc_after_pickup_handling' => ['order_cancellations', ['from_status' => 'picked_up']],
            'chk_oc_amounts' => ['order_cancellations', ['cash_collected_amount' => -1]],
            'chk_oc_by' => ['order_cancellations', ['cancelled_by' => 'driver']],
            'chk_oc_customer_pending_only' => ['order_cancellations', ['cancelled_by' => 'customer', 'from_status' => 'accepted']],
            'chk_oc_driver_handling' => ['order_cancellations', ['driver_handling' => 'lost']],
            'chk_oc_from_status' => ['order_cancellations', ['from_status' => 'delivered']],
            'chk_oc_reason' => ['order_cancellations', ['reason_code' => 'whim']],
            'chk_oio_total' => ['order_item_options', ['total_price_delta' => $raw('total_price_delta + 1')]],
            'chk_oi_line' => ['order_items', ['line_total' => $raw('line_total + 1')]],
            'chk_oi_quantity' => ['order_items', ['quantity' => 0, 'line_subtotal' => 0, 'line_total' => 0, 'offer_discount_amount' => 0]],
            'chk_oi_unit_price' => ['order_items', ['base_unit_price' => $raw('base_unit_price + 1')]],
            'chk_op_amounts' => ['order_payments', ['refunded_amount' => $raw('collected_amount + 1')]],
            'chk_op_status' => ['order_payments', ['status' => 'lost']],
            'chk_or_amount' => ['order_refunds', ['amount' => 0]],
            'chk_osh_actor' => ['order_status_histories', ['actor_type' => 'robot']],
            'chk_orders_amounts' => ['orders', ['delivery_fee_original' => -1]],
            'chk_orders_free_delivery' => ['orders', ['free_delivery_applied' => 1, 'plus_cycle_id' => null]],
            'chk_orders_rate' => ['orders', ['exchange_rate' => 0]],
            'chk_orders_schedule' => ['orders', ['type' => 'scheduled', 'scheduled_for' => null]],
            'chk_orders_status' => ['orders', ['status' => 'lost']],
            'chk_orders_total' => ['orders', ['total' => $raw('total + 1')]],
            'chk_orders_type' => ['orders', ['type' => 'express']],
            'chk_otps_binding' => ['otps', ['verifiable_id' => null, 'destination_hash' => null]],
            'chk_payment_methods_kind' => ['payment_methods', ['kind' => 'crypto']],
            'chk_pcd_source' => ['plus_cycle_discounts', ['source' => 'gift']],
            'chk_pcd_type' => ['plus_cycle_discounts', ['discount_type' => 'bogo']],
            'chk_pcd_values' => ['plus_cycle_discounts', ['snapshot_discount_value' => 0]],
            'chk_pcy_status' => ['plus_cycles', ['status' => 'paused']],
            'chk_pcy_usage' => ['plus_cycles', ['free_deliveries_used' => $raw('free_deliveries_total + 1')]],
            'chk_pcy_window' => ['plus_cycles', ['ends_at' => $raw('starts_at')]],
            'chk_pm_custom_days' => ['plus_memberships', ['custom_duration_days' => 5]],
            'chk_pm_policy' => ['plus_memberships', ['renewal_policy' => 'weekly']],
            'chk_pm_status' => ['plus_memberships', ['status' => 'paused']],
            'chk_plus_plans_duration' => ['plus_plans', ['default_duration_days' => 0]],
            'chk_plus_plans_price' => ['plus_plans', ['display_price' => -1]],
            'chk_pr_plan_required' => ['plus_requests', ['requested_plan_id' => null]],
            'chk_pr_source' => ['plus_requests', ['source' => 'robot']],
            'chk_pr_status' => ['plus_requests', ['status' => 'lost']],
            'chk_pr_type' => ['plus_requests', ['type' => 'upgrade']],
            'chk_psd_amounts' => ['plus_store_discounts', ['max_discount_amount' => 0]],
            'chk_psd_one_owner' => ['plus_store_discounts', ['plus_membership_id' => $raw('(SELECT id FROM plus_memberships LIMIT 1)')]],
            'chk_psd_shape' => ['plus_store_discounts', ['discount_value' => 0]],
            'chk_pog_kind' => ['product_option_groups', ['kind' => 'color']],
            'chk_pog_selection' => ['product_option_groups', ['max_select' => 0]],
            'chk_pog_size_single' => ['product_option_groups', ['kind' => 'size']],
            'chk_po_max_quantity' => ['product_options', ['max_quantity' => 0]],
            'chk_prt_rating' => ['product_ratings', ['rating' => 6]],
            'chk_products_rating' => ['products', ['rating_sum' => $raw('rating_count * 5 + 1')], ['id' => $data['product']->id]],
            'chk_settings_key_format' => ['settings', ['key' => 'Bad Key']],
            'chk_stores_contact_phone' => ['stores', ['contact_phone' => '0912345678']],
            'chk_stores_contact_whatsapp' => ['stores', ['contact_whatsapp' => '0912345678']],
            'chk_user_addresses_coordinates' => ['user_addresses', ['lat' => 91]],
            'chk_users_account_status' => ['users', ['account_status' => 'banned']],
            'chk_users_otp_channel' => ['users', ['otp_channel' => 'email']],
            'chk_users_phone_e164' => ['users', ['phone' => '0912345678']],
            'chk_wt_amounts' => ['wallet_transactions', ['amount' => 0]],
            'chk_wt_direction' => ['wallet_transactions', ['direction' => 'out']],
            'chk_wt_type' => ['wallet_transactions', ['type' => 'gift']],
            'chk_wallets_balance' => ['wallets', ['balance' => -1]],
        ];

        $database = DB::connection()->getDatabaseName();
        $declared = collect(DB::select('SELECT constraint_name AS name FROM information_schema.check_constraints WHERE constraint_schema = ?', [$database]))->pluck('name');
        $this->assertEqualsCanonicalizing($declared->all(), array_keys($violations), 'Every CHECK constraint in the schema must have a rejection case here.');

        foreach ($violations as $constraint => [$table, $set]) {
            $where = $violations[$constraint][2] ?? [];
            $this->assertTrue(DB::table($table)->where($where)->exists(), "No seeded row in {$table} for {$constraint}.");

            // UPDATE ... LIMIT 1 also covers tables with a composite primary key.
            $this->assertRejected(3819, fn () => DB::table($table)->where($where)->limit(1)->update($set), $constraint);
        }
    }

    public function test_generated_marker_columns_enforce_conditional_uniqueness(): void
    {
        $data = $this->seedFullDataset();
        $user = $data['user'];

        // One default currency.
        $this->assertRejected(1062, fn () => DB::table('currencies')->where('id', $data['syp']->id)->update(['is_default' => 1, 'exchange_rate' => 1]));
        // One in-flight re-denomination.
        $this->assertRejected(1062, fn () => DB::table('currency_redenominations')->insert(['from_currency_id' => $data['usd']->id, 'to_currency_id' => $data['syp']->id, 'conversion_rate' => 2, 'idempotency_key' => 'r2', 'requested_by_admin_id' => $data['admin']->id, 'status' => 'running']));
        // One pending cancellation request per order; resolved ones do not count.
        $orderId = DB::table('order_cancellation_requests')->value('order_id');
        $this->assertRejected(1062, fn () => DB::table('order_cancellation_requests')->insert(['order_id' => $orderId]));
        DB::table('order_cancellation_requests')->insert(['order_id' => $orderId, 'status' => 'rejected']);
        // One active cycle per membership.
        $cycle = DB::table('plus_cycles')->first();
        $this->assertRejected(1062, fn () => DB::table('plus_cycles')->insert(array_merge(array_diff_key((array) $cycle, ['id' => 0, 'active_marker' => 0]), ['cycle_number' => 2, 'plus_request_id' => null])));
        // One pending PLUS request per user and type.
        PlusRequest::create(['user_id' => $user->id, 'type' => 'renew']);
        $this->assertRejected(1062, fn () => DB::table('plus_requests')->insert(['user_id' => $user->id, 'type' => 'renew']));
        // One default address per user; a soft-deleted default frees the slot.
        $address = ['user_id' => $user->id, 'city_id' => $data['city']->id, 'address_line' => 'Street 2', 'lat' => 33.5, 'lng' => 36.3, 'is_default' => 1];
        $this->assertRejected(1062, fn () => DB::table('user_addresses')->insert($address));
        DB::table('user_addresses')->where('user_id', $user->id)->update(['deleted_at' => now()]);
        DB::table('user_addresses')->insert($address);
        // One fee band per scope (area / city / global) and start distance.
        DB::table('delivery_fee_rules')->insert(['distance_from_km' => 0, 'distance_to_km' => 3, 'base_fee' => 1, 'per_km_fee' => 0]);
        $this->assertRejected(1062, fn () => DB::table('delivery_fee_rules')->insert(['distance_from_km' => 0, 'distance_to_km' => 9, 'base_fee' => 1, 'per_km_fee' => 0]));
        // A verified email is unique (generated verified_email column).
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->whereNotNull('verified_email')->count());

        $markers = collect(DB::select("SELECT CONCAT(table_name, '.', column_name) AS col, extra AS extra FROM information_schema.columns WHERE table_schema = ? AND extra LIKE '%GENERATED%' AND table_name <> 'failed_jobs'", [DB::connection()->getDatabaseName()]));
        $this->assertEqualsCanonicalizing([
            'currencies.default_marker', 'currency_redenominations.inflight_marker', 'delivery_fee_rules.scope_key',
            'order_cancellation_requests.pending_marker', 'plus_cycles.active_marker', 'plus_requests.pending_key',
            'user_addresses.default_marker', 'users.verified_email',
        ], $markers->pluck('col')->all());
        $this->assertTrue($markers->every(fn ($row) => str_contains($row->extra, 'STORED')), 'MySQL markers must be STORED generated columns.');
    }

    public function test_composite_foreign_keys_keep_store_and_product_ownership(): void
    {
        $data = $this->seedFullDataset();
        $otherStore = $this->store();
        $otherProduct = $this->product($otherStore);
        $otherGroup = ProductOptionGroup::create(['product_id' => $otherProduct->id, 'name' => ['en' => 'Size'], 'kind' => 'choice', 'min_select' => 0, 'max_select' => 1]);
        $otherOption = ProductOption::create(['option_group_id' => $otherGroup->id, 'product_id' => $otherProduct->id, 'name' => ['en' => 'Big']]);
        $branchProduct = BranchProduct::where('product_id', $data['product']->id)->firstOrFail();

        // A branch may only sell its own store's products.
        $this->assertRejected(1452, fn () => DB::table('branch_products')->insert(['branch_id' => $data['branch']->id, 'store_id' => $otherStore->id, 'product_id' => $otherProduct->id, 'price' => 1]));
        $this->assertRejected(1452, fn () => DB::table('branch_products')->insert(['branch_id' => $data['branch']->id, 'store_id' => $data['store']->id, 'product_id' => $otherProduct->id, 'price' => 1]));
        // An option belongs to a group of the same product.
        $this->assertRejected(1452, fn () => DB::table('product_options')->insert(['option_group_id' => $otherGroup->id, 'product_id' => $data['product']->id, 'name' => '{"en":"x"}']));
        // A branch option price must use an option of the branch product's own product.
        $this->assertRejected(1452, fn () => DB::table('branch_product_options')->insert(['branch_product_id' => $branchProduct->id, 'product_id' => $data['product']->id, 'product_option_id' => $otherOption->id, 'price_delta' => 0]));
        $this->assertRejected(1452, fn () => DB::table('branch_product_options')->insert(['branch_product_id' => $branchProduct->id, 'product_id' => $otherProduct->id, 'product_option_id' => $otherOption->id, 'price_delta' => 0]));

        $composite = collect(DB::select('SELECT constraint_name AS name FROM information_schema.key_column_usage WHERE table_schema = ? AND referenced_table_name IS NOT NULL GROUP BY table_name, constraint_name HAVING COUNT(*) > 1', [DB::connection()->getDatabaseName()]))->pluck('name');
        $this->assertEqualsCanonicalizing(['fk_bpo_bp_product', 'fk_bpo_option_product', 'fk_bp_branch_store', 'fk_bp_product_store', 'fk_po_group_product'], $composite->all());
    }

    public function test_restrict_foreign_keys_protect_history(): void
    {
        $data = $this->seedFullDataset();

        foreach ([
            'users' => $data['user']->id,           // orders, wallet, addresses
            'stores' => $data['store']->id,         // branches, products
            'branches' => $data['branch']->id,      // orders
            'products' => $data['product']->id,     // order items, ratings
            'currencies' => $data['usd']->id,       // orders, payments
            'cities' => $data['city']->id,          // branches
            'admins' => $data['admin']->id,         // audit, cancellations
            'payment_methods' => DB::table('order_payments')->value('payment_method_id'),
            'orders' => DB::table('orders')->min('id'),
            'plus_plans' => $data['plan']->id,
        ] as $table => $id) {
            $this->assertRejected(1451, fn () => DB::table($table)->where('id', $id)->delete(), null, "delete from {$table}");
        }

        $this->assertRejected(1452, fn () => DB::table('orders')->where('id', DB::table('orders')->min('id'))->update(['branch_id' => 999999]));
    }

    public function test_relationships_load_on_mysql(): void
    {
        $data = $this->seedFullDataset();

        $order = Order::with(['items.options', 'payments.refunds', 'statusHistories', 'cancellation', 'branch.store', 'user'])->whereHas('payments.refunds')->firstOrFail();
        $this->assertTrue($order->items->isNotEmpty());
        $this->assertTrue($order->payments->first()->refunds->isNotEmpty());
        $this->assertSame($data['store']->id, $order->branch->store->id);

        $product = Product::with(['optionGroups.options', 'branchProducts', 'categories', 'ratings'])->findOrFail($data['product']->id);
        $this->assertSame(1, $product->optionGroups->first()->options->count());
        $this->assertSame(1, $product->ratings->count());
        $this->assertSame(1, $product->rating_count);

        $store = Store::with(['branches.openingHours', 'products', 'categories'])->findOrFail($data['store']->id);
        $this->assertSame(7, $store->branches->first()->openingHours->count());

        $user = User::with(['wallets.transactions', 'addresses', 'orders', 'plusMembership.cycles.discounts'])->findOrFail($data['user']->id);
        $this->assertSame('10.0000', (string) $user->wallets->first()->balance);
        $this->assertSame(1, $user->plusMembership->cycles->first()->discounts->count());

        $category = Category::create(['name' => ['en' => 'Root'], 'slug' => 'root']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $category->id]);
        $this->assertSame($category->id, $child->parent->id);
        $this->assertSame(1, Branch::whereHas('store', fn ($query) => $query->whereKey($data['store']->id))->count());
    }
}
