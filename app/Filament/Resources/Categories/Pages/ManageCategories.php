<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\UnderStores;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCategories extends ManageRecords
{
    use UnderStores;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToStoresAction(), CreateAction::make()];
    }
}
