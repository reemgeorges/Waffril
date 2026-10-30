<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;

/**
 * WAFFRLI (2026-10-31) — audited catalog availability switches used by
 * the admin panel (and reusable by future APIs).
 */
class CatalogAdminService
{
    public function setBranchProductAvailability(BranchProduct $row, bool $available, Admin $admin): BranchProduct
    {
        $this->authorize($admin);
        $row->update(['is_available' => $available]);
        AdminAudit::log('catalog.branch_product_availability', $row, ['available' => $available], $admin);

        return $row;
    }

    public function setBranchOptionAvailability(BranchProductOption $row, bool $available, Admin $admin): BranchProductOption
    {
        $this->authorize($admin);
        $row->update(['is_available' => $available]);
        AdminAudit::log('catalog.branch_option_availability', $row, ['available' => $available], $admin);

        return $row;
    }

    public function setBranchAcceptingOrders(Branch $branch, bool $accepting, Admin $admin): Branch
    {
        $this->authorize($admin);
        $branch->update(['is_accepting_orders' => $accepting]);
        AdminAudit::log('catalog.branch_accepting_orders', $branch, ['accepting' => $accepting], $admin);

        return $branch;
    }

    protected function authorize(Admin $admin): void
    {
        if (! AdminPermissions::allows($admin, 'catalog.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }
    }
}
