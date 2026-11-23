<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\StoreResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Stores: the catalog's home (UX overhaul 2026-11-12). Catalog category
 * shortcuts open from here. Order/driver reviews are moderated from
 * their dedicated Filament resources instead.
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
            ])->label(__('admin.resources.category.plural'))->icon('heroicon-m-adjustments-horizontal')->button()->color('gray'),
            CreateAction::make(),
        ];
    }
}
