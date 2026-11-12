<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\StoreResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Stores: the catalog's home (UX overhaul 2026-11-12). Categories and the
 * ratings moderation queue are not in the sidebar; they open from here
 * (and inline from the store / product forms), each with its own policy.
 */
class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('storeCategories')->label(StoreCategoryResource::getPluralModelLabel())->icon('heroicon-m-squares-2x2')
                    ->url(fn () => StoreCategoryResource::getUrl('index'))->visible(fn () => StoreCategoryResource::canViewAny()),
                Action::make('productCategories')->label(CategoryResource::getPluralModelLabel())->icon('heroicon-m-tag')
                    ->url(fn () => CategoryResource::getUrl('index'))->visible(fn () => CategoryResource::canViewAny()),
                Action::make('ratings')->label(__('admin.stores.ratings_queue'))->icon('heroicon-m-star')
                    ->url(fn () => ProductRatingResource::getUrl('index'))->visible(fn () => ProductRatingResource::canViewAny()),
            ])->label(__('admin.stores.categories_and_ratings'))->icon('heroicon-m-adjustments-horizontal')->button()->color('gray'),
            CreateAction::make(),
        ];
    }
}
