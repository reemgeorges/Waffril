<?php

namespace App\Filament\Resources\StoreCategories\Pages;

use App\Filament\Concerns\UnderStores;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageStoreCategories extends ManageRecords
{
    use UnderStores;

    protected static string $resource = StoreCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToStoresAction(), CreateAction::make()->icon('heroicon-m-plus')->modalWidth(Width::TwoExtraLarge)];
    }
}
