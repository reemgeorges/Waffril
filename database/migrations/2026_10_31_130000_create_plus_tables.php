<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P4 PLUS (2026-10-31).
 *
 * - plus_memberships: the logical subscription (one row per customer).
 * - plus_store_discounts: plan definitions AND admin-approved per-member
 *   exceptions in one table (exactly one owner). FKs are RESTRICT because
 *   MySQL forbids CHECK constraints on columns used by cascading FKs.
 * - plus_requests: new / renew / change / cancel / custom; at most one
 *   pending request per (customer, type).
 * - plus_cycles: one row per ADMIN-CONFIRMED cycle; at most one active
 *   cycle per membership.
 * - plus_cycle_discounts: immutable snapshot_* values + mutable op_*
 *   values (converted only by the P7 currency job, which records its run).
 *
 * Subscription payment/agreement is off-platform; nothing here charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plus_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique('uq_plus_plans_code');
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedSmallInteger('default_duration_days')->default(30);
            $table->unsignedSmallInteger('free_deliveries_per_cycle')->default(0);
            $table->decimal('display_price', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plus_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique('uq_plus_memberships_user')->constrained('users', 'id', 'fk_pm_user')->restrictOnDelete();
            $table->foreignId('plus_plan_id')->constrained('plus_plans', 'id', 'fk_pm_plan')->restrictOnDelete();
            $table->foreignId('next_plan_id')->nullable()->constrained('plus_plans', 'id', 'fk_pm_next_plan')->restrictOnDelete();
            $table->string('renewal_policy', 16);
            $table->unsignedSmallInteger('custom_duration_days')->nullable();
            $table->unsignedSmallInteger('free_deliveries_override')->nullable();
            $table->foreignId('override_approved_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_pm_override_admin')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->foreignId('policy_set_by_admin_id')->constrained('admins', 'id', 'fk_pm_policy_admin')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('plus_store_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plus_plan_id')->nullable()->constrained('plus_plans', 'id', 'fk_psd_plan')->restrictOnDelete();
            $table->foreignId('plus_membership_id')->nullable()->constrained('plus_memberships', 'id', 'fk_psd_membership')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_psd_store')->restrictOnDelete();
            $table->boolean('is_excluded')->default(false);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->nullable();
            $table->decimal('max_discount_amount', 18, 4)->nullable();
            $table->decimal('min_order_amount', 18, 4)->nullable();
            $table->unsignedSmallInteger('max_uses_per_cycle')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_psd_admin')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plus_plan_id', 'store_id'], 'uq_psd_plan_store');
            $table->unique(['plus_membership_id', 'store_id'], 'uq_psd_membership_store');
        });

        Schema::create('plus_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_pr_user')->restrictOnDelete();
            $table->foreignId('plus_membership_id')->nullable()->constrained('plus_memberships', 'id', 'fk_pr_membership')->restrictOnDelete();
            $table->string('type', 16);
            $table->foreignId('requested_plan_id')->nullable()->constrained('plus_plans', 'id', 'fk_pr_plan')->restrictOnDelete();
            $table->string('customer_note', 1000)->nullable();
            $table->string('source', 16)->default('customer');
            $table->string('status', 16)->default('pending');
            $table->string('pending_key', 40)->nullable()->storedAs(
                "CASE WHEN status = 'pending' THEN ".SchemaConstraints::concat('user_id', "':'", 'type').' ELSE NULL END'
            );
            $table->string('admin_note', 1000)->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins', 'id', 'fk_pr_admin')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('pending_key', 'uq_pr_one_pending_per_type');
            $table->index(['status', 'created_at'], 'idx_pr_status_created');
        });

        Schema::create('plus_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plus_membership_id')->constrained('plus_memberships', 'id', 'fk_pcy_membership')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_pcy_user')->restrictOnDelete();
            $table->foreignId('plus_plan_id')->constrained('plus_plans', 'id', 'fk_pcy_plan')->restrictOnDelete();
            $table->foreignId('plus_request_id')->nullable()->unique('uq_pcy_request')->constrained('plus_requests', 'id', 'fk_pcy_request')->restrictOnDelete();
            $table->unsignedSmallInteger('cycle_number');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('scheduled');
            $table->unsignedBigInteger('active_marker')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN plus_membership_id ELSE NULL END");
            $table->json('plan_name');
            $table->string('renewal_policy', 16);
            $table->foreignId('snapshot_currency_id')->constrained('currencies', 'id', 'fk_pcy_currency')->restrictOnDelete();
            $table->unsignedSmallInteger('free_deliveries_total');
            $table->unsignedSmallInteger('free_deliveries_used')->default(0);
            $table->foreignId('confirmed_by_admin_id')->constrained('admins', 'id', 'fk_pcy_admin')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['plus_membership_id', 'cycle_number'], 'uq_pcy_membership_number');
            $table->unique('active_marker', 'uq_pcy_one_active');
            $table->index(['user_id', 'status', 'starts_at', 'ends_at'], 'idx_pcy_user_status_window');
        });

        Schema::create('plus_cycle_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plus_cycle_id')->constrained('plus_cycles', 'id', 'fk_pcd_cycle')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores', 'id', 'fk_pcd_store')->restrictOnDelete();
            $table->string('source', 16);
            $table->string('discount_type', 10);
            $table->foreignId('snapshot_currency_id')->constrained('currencies', 'id', 'fk_pcd_snapshot_currency')->restrictOnDelete();
            $table->decimal('snapshot_discount_value', 18, 4);
            $table->decimal('snapshot_max_discount_amount', 18, 4)->nullable();
            $table->decimal('snapshot_min_order_amount', 18, 4)->nullable();
            $table->unsignedSmallInteger('max_uses_per_cycle')->nullable();
            $table->foreignId('op_currency_id')->constrained('currencies', 'id', 'fk_pcd_op_currency')->restrictOnDelete();
            $table->decimal('op_discount_value', 18, 4);
            $table->decimal('op_max_discount_amount', 18, 4)->nullable();
            $table->decimal('op_min_order_amount', 18, 4)->nullable();
            $table->foreignId('op_redenomination_id')->nullable()->constrained('currency_redenominations', 'id', 'fk_pcd_redenomination')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['plus_cycle_id', 'store_id'], 'uq_pcd_cycle_store');
        });

        SchemaConstraints::addCheck('plus_plans', 'chk_plus_plans_duration', 'default_duration_days >= 1');
        SchemaConstraints::addCheck('plus_plans', 'chk_plus_plans_price', 'display_price IS NULL OR display_price >= 0');
        SchemaConstraints::addCheck('plus_memberships', 'chk_pm_policy', "renewal_policy IN ('monthly', 'one_time', 'custom')");
        SchemaConstraints::addCheck('plus_memberships', 'chk_pm_custom_days', "(renewal_policy = 'custom') = (custom_duration_days IS NOT NULL)");
        SchemaConstraints::addCheck('plus_memberships', 'chk_pm_status', "status IN ('active', 'cancelled', 'ended')");
        SchemaConstraints::addCheck('plus_store_discounts', 'chk_psd_one_owner', '(plus_plan_id IS NULL) <> (plus_membership_id IS NULL)');
        SchemaConstraints::addCheck(
            'plus_store_discounts',
            'chk_psd_shape',
            '(is_excluded = 1 AND plus_membership_id IS NOT NULL AND discount_type IS NULL) OR '
            ."(is_excluded = 0 AND discount_type IN ('percent', 'fixed') AND discount_value > 0 "
            ."AND (discount_type <> 'percent' OR discount_value <= 100))"
        );
        SchemaConstraints::addCheck('plus_store_discounts', 'chk_psd_amounts', '(max_discount_amount IS NULL OR max_discount_amount > 0) AND (min_order_amount IS NULL OR min_order_amount >= 0)');
        SchemaConstraints::addCheck('plus_requests', 'chk_pr_type', "type IN ('new', 'renew', 'change', 'cancel', 'custom')");
        SchemaConstraints::addCheck('plus_requests', 'chk_pr_status', "status IN ('pending', 'approved', 'rejected', 'withdrawn')");
        SchemaConstraints::addCheck('plus_requests', 'chk_pr_source', "source IN ('customer', 'system')");
        SchemaConstraints::addCheck('plus_requests', 'chk_pr_plan_required', "type NOT IN ('new', 'change') OR requested_plan_id IS NOT NULL");
        SchemaConstraints::addCheck('plus_cycles', 'chk_pcy_status', "status IN ('scheduled', 'active', 'expired', 'cancelled')");
        SchemaConstraints::addCheck('plus_cycles', 'chk_pcy_window', 'ends_at > starts_at');
        SchemaConstraints::addCheck('plus_cycles', 'chk_pcy_usage', 'free_deliveries_used <= free_deliveries_total');
        SchemaConstraints::addCheck('plus_cycle_discounts', 'chk_pcd_source', "source IN ('plan', 'exception')");
        SchemaConstraints::addCheck('plus_cycle_discounts', 'chk_pcd_type', "discount_type IN ('percent', 'fixed')");
        SchemaConstraints::addCheck('plus_cycle_discounts', 'chk_pcd_values', 'snapshot_discount_value > 0 AND op_discount_value > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('plus_cycle_discounts');
        Schema::dropIfExists('plus_cycles');
        Schema::dropIfExists('plus_requests');
        Schema::dropIfExists('plus_store_discounts');
        Schema::dropIfExists('plus_memberships');
        Schema::dropIfExists('plus_plans');
    }
};
