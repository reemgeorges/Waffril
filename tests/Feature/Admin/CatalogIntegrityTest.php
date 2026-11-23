<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Models\BranchHourException;
use App\Models\BranchOpeningHour;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Category;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Setting;
use App\Services\Catalog\BranchHoursService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\Support\WaffrliFixtures;

/**
 * WAFFRLI (2026-10-31) — store/branch/product integrity, option pricing,
 * effective branch hours and rating aggregates/moderation.
 */
class CatalogIntegrityTest extends AdminPanelTestCase
{
    use WaffrliFixtures;

    public function test_branch_cannot_list_another_stores_product(): void
    {
        $city = $this->city();
        $branch = $this->branch($this->store(), $city);
        $foreign = $this->product($this->store());

        $this->expectException(QueryException::class);
        BranchProduct::create(['branch_id' => $branch->id, 'product_id' => $foreign->id, 'price' => 1]);
    }

    public function test_branch_option_price_must_belong_to_the_same_product(): void
    {
        $store = $this->store();
        $branch = $this->branch($store, $this->city());
        $burger = $this->product($store);
        $pizza = $this->product($store);
        $row = BranchProduct::create(['branch_id' => $branch->id, 'product_id' => $burger->id, 'price' => 5]);
        $sizes = ProductOptionGroup::create(['product_id' => $pizza->id, 'name' => ['en' => 'Size', 'ar' => 'الحجم'], 'kind' => 'size', 'min_select' => 1, 'max_select' => 1]);
        $large = ProductOption::create(['option_group_id' => $sizes->id, 'name' => ['en' => 'Large', 'ar' => 'كبير']]);
        $this->assertSame($pizza->id, $large->product_id);

        $this->expectException(QueryException::class);
        BranchProductOption::create(['branch_product_id' => $row->id, 'product_option_id' => $large->id, 'price_delta' => 1]);
    }

    public function test_options_get_branch_specific_prices(): void
    {
        $store = $this->store();
        $a = $this->branch($store, $this->city());
        $b = $this->branch($store, $this->city());
        $burger = $this->product($store);
        $extras = ProductOptionGroup::create(['product_id' => $burger->id, 'name' => ['en' => 'Extras', 'ar' => 'إضافات'], 'kind' => 'addon', 'max_select' => 3]);
        $cheese = ProductOption::create(['option_group_id' => $extras->id, 'name' => ['en' => 'Cheese', 'ar' => 'جبنة'], 'max_quantity' => 2]);

        foreach ([[$a, '1.5'], [$b, '2']] as [$branch, $delta]) {
            $row = BranchProduct::create(['branch_id' => $branch->id, 'product_id' => $burger->id, 'price' => 10]);
            BranchProductOption::create(['branch_product_id' => $row->id, 'product_option_id' => $cheese->id, 'price_delta' => $delta]);
        }

        $this->assertEqualsCanonicalizing(['1.5000', '2.0000'], BranchProductOption::where('product_option_id', $cheese->id)->pluck('price_delta')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(2, $burger->branchOptionPrices()->count());
    }

    public function test_effective_hours_weekly_overnight_and_exceptions(): void
    {
        Setting::create(['key' => 'operations.timezone', 'value' => 'UTC']);
        $admin = $this->superAdmin();
        $branch = $this->branch($this->store(), $this->city(), alwaysOpen: false);
        BranchOpeningHour::create(['branch_id' => $branch->id, 'day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00']);
        $hours = app(BranchHoursService::class);

        $friday = Carbon::parse('2026-11-06 20:00', 'UTC');
        $this->assertTrue($hours->isOpenAt($branch, $friday));
        $this->assertTrue($hours->isOpenAt($branch, Carbon::parse('2026-11-07 01:30', 'UTC')), 'overnight slot continues after midnight');
        $this->assertFalse($hours->isOpenAt($branch, Carbon::parse('2026-11-07 03:00', 'UTC')));
        $this->assertFalse($hours->isOpenAt($branch, Carbon::parse('2026-11-06 12:00', 'UTC')));

        BranchHourException::create(['branch_id' => $branch->id, 'kind' => 'open', 'starts_at' => '2026-11-06 10:00', 'ends_at' => '2026-11-06 14:00', 'created_by_admin_id' => $admin->id]);
        $this->assertTrue($hours->isOpenAt($branch, Carbon::parse('2026-11-06 12:00', 'UTC')), 'special opening');

        BranchHourException::create(['branch_id' => $branch->id, 'kind' => 'closed', 'starts_at' => '2026-11-06 19:00', 'ends_at' => '2026-11-06 21:00', 'created_by_admin_id' => $admin->id]);
        $this->assertFalse($hours->isOpenAt($branch, $friday), 'temporary closure wins');
    }

    public function test_category_tree_refuses_self_parent_and_cycles(): void
    {
        $root = Category::create(['name' => ['en' => 'Root'], 'slug' => 'root']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $root->id]);
        $grandchild = Category::create(['name' => ['en' => 'Grandchild'], 'slug' => 'grandchild', 'parent_id' => $child->id]);

        foreach ([[$root, $root], [$root, $grandchild]] as [$category, $parent]) {
            try {
                $category->fresh()->update(['parent_id' => $parent->id]);
                $this->fail('A category must not become its own ancestor.');
            } catch (BusinessRuleException) {
                $this->assertNull($root->fresh()->parent_id);
            }
        }

        $grandchild->update(['parent_id' => $root->id]);
        $this->assertSame($root->id, $grandchild->fresh()->parent_id);
    }
}
