<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\Stores\StoreResource;
use Filament\Actions\Action;

/**
 * UX overhaul (2026-11-12): catalog pages that are not in the sidebar
 * (categories, ratings, the global branch / product lists) read as part of
 * Stores: breadcrumbs "Stores › {page}" and a "Back to stores" button.
 */
trait UnderStores
{
    public function getBreadcrumbs(): array
    {
        if (! StoreResource::canViewAny()) {
            return parent::getBreadcrumbs();
        }

        return [StoreResource::getUrl('index') => StoreResource::getPluralModelLabel(), $this->getTitle()];
    }

    protected function backToStoresAction(): Action
    {
        return Action::make('backToStores')->label(__('admin.stores.back_to_stores'))->color('gray')
            ->icon('heroicon-m-building-storefront')
            ->url(fn () => StoreResource::getUrl('index'))
            ->visible(fn () => StoreResource::canViewAny());
    }
}
