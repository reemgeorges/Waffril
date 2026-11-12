<?php

namespace App\Filament\Support;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Models\Category;
use App\Models\StoreCategory;
use App\Services\Catalog\CategoryDeletionService;
use App\Support\Settings\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * UX overhaul (2026-11-12): "Delete" for store and product categories.
 * Shows how many stores/products use the category, asks for the category
 * they move to (pre-filled from the platform fallback setting) and an
 * explicit confirmation, then calls CategoryDeletionService. Stores and
 * products are never deleted.
 */
final class SafeCategoryDelete
{
    public static function make(string $kind): Action
    {
        $isStore = $kind === 'store';
        $relation = $isStore ? 'stores' : 'products';
        $model = $isStore ? StoreCategory::class : Category::class;
        $usage = fn (Model $record) => app(CategoryDeletionService::class)->usage($record, $relation);

        return Action::make('deleteSafely')
            ->label(__('filament-actions::delete.single.label'))
            ->icon('heroicon-m-trash')->color('danger')->iconButton()
            ->tooltip(__('filament-actions::delete.single.label'))
            ->visible(fn (Model $record) => ! $record->trashed() && self::canDelete($record))
            ->modalHeading(fn (Model $record) => __('admin.categories.delete_heading', ['name' => $record->name]))
            ->modalIcon('heroicon-o-trash')
            ->modalDescription(fn (Model $record) => $usage($record) > 0
                ? __("admin.categories.delete_in_use_{$kind}", ['count' => $usage($record)])
                : __('admin.categories.delete_unused'))
            ->schema(fn (Model $record) => $usage($record) === 0 ? [] : [
                Select::make('replacement_id')->label(__("admin.categories.move_{$kind}_to"))
                    ->options(fn () => $model::query()->whereKeyNot($record->getKey())->orderBy('sort_order')->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->name]))
                    ->default(function () use ($isStore, $record) {
                        $fallback = PlatformSettings::get($isStore ? 'catalog.fallback_store_category_id' : 'catalog.fallback_product_category_id');

                        return $fallback && (int) $fallback !== $record->getKey() ? (int) $fallback : null;
                    })
                    ->searchable()->required()
                    ->helperText(__('admin.categories.move_help')),
                Checkbox::make('confirm')->label(__("admin.categories.confirm_move_{$kind}", ['count' => $usage($record)]))->accepted(),
            ])
            ->modalSubmitActionLabel(__('admin.categories.delete_and_move'))
            ->action(function (Model $record, array $data) use ($isStore, $model) {
                $replacement = isset($data['replacement_id']) ? $model::query()->find($data['replacement_id']) : null;
                BusinessAction::run(fn ($admin) => $isStore
                    ? app(CategoryDeletionService::class)->deleteStoreCategory($record, $replacement, $admin)
                    : app(CategoryDeletionService::class)->deleteProductCategory($record, $replacement, $admin));
            });
    }

    protected static function canDelete(Model $record): bool
    {
        $resource = $record instanceof StoreCategory
            ? StoreCategoryResource::class
            : CategoryResource::class;

        return $resource::can('delete', $record);
    }
}
