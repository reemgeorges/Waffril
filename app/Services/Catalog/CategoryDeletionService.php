<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\Category;
use App\Models\StoreCategory;
use App\Services\Audit\AdminAudit;
use App\Support\Auth\AdminPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * UX overhaul (2026-11-12): deleting a store or product category never
 * leaves a store or product without its categorisation.
 *
 *  - Every store/product linked to the category is attached to an explicitly
 *    chosen replacement (pre-filled from the platform's fallback setting),
 *    then the deleted category is detached from it.
 *  - The replacement is required whenever the category is in use, must be a
 *    different, existing, non-deleted category, and the admin confirms.
 *  - Stores and products themselves are never deleted. Delete is the usual
 *    soft delete (restorable); the affected ids are kept in the audit log so
 *    a restore can be re-linked by hand.
 *  - One transaction; permission checked here as well as in the panel.
 */
class CategoryDeletionService
{
    /** @return array{affected: int, replacement_id: ?int} */
    public function deleteStoreCategory(StoreCategory $category, ?StoreCategory $replacement, Admin $admin): array
    {
        return $this->delete($category, $replacement, 'stores', 'store_category', $admin);
    }

    /** @return array{affected: int, replacement_id: ?int} */
    public function deleteProductCategory(Category $category, ?Category $replacement, Admin $admin): array
    {
        if (Category::query()->where('parent_id', $category->getKey())->exists()) {
            throw new BusinessRuleException(__('admin.categories.has_children'));
        }

        return $this->delete($category, $replacement, 'products', 'category', $admin);
    }

    /** How many stores/products use the category (for the dialog). */
    public function usage(Model $category, string $relation): int
    {
        return $category->{$relation}()->withoutGlobalScopes()->count();
    }

    protected function delete(Model $category, ?Model $replacement, string $relation, string $auditKey, Admin $admin): array
    {
        if (! AdminPermissions::allows($admin, 'catalog.manage')) {
            throw new BusinessRuleException(__('admin.errors.forbidden'), 403);
        }

        return DB::transaction(function () use ($category, $replacement, $relation, $auditKey, $admin) {
            $category = $category->newQuery()->lockForUpdate()->findOrFail($category->getKey());
            $ids = $category->{$relation}()->withoutGlobalScopes()->pluck($category->{$relation}()->getRelated()->getQualifiedKeyName())->all();

            if ($ids !== []) {
                if ($replacement === null) {
                    throw new BusinessRuleException(__('admin.categories.replacement_required', ['count' => count($ids)]));
                }
                $replacement = $replacement->newQuery()->findOrFail($replacement->getKey());
                if ($replacement->is($category) || $replacement->trashed()) {
                    throw new BusinessRuleException(__('admin.categories.replacement_invalid'));
                }
                // Attach the replacement to every affected record (no duplicates), then unlink.
                $replacement->{$relation}()->withoutGlobalScopes()->syncWithoutDetaching($ids);
                $category->{$relation}()->withoutGlobalScopes()->detach($ids);
            }

            $category->delete();
            AdminAudit::log("catalog.{$auditKey}_deleted", $category, [
                'moved_to' => $replacement?->getKey(),
                'affected_ids' => implode(',', $ids),
                'count' => count($ids),
            ], $admin);

            return ['affected' => count($ids), 'replacement_id' => $replacement?->getKey()];
        });
    }
}
