<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\UnderStores;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    use UnderStores;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToStoresAction(), CreateAction::make()];
    }
}
