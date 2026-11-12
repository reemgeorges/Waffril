<?php

namespace App\Filament\Resources\ProductRatings\Pages;

use App\Filament\Concerns\UnderStores;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\Pages\ListRecords;

class ListProductRatings extends ListRecords
{
    use UnderStores;

    protected static string $resource = ProductRatingResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToStoresAction()];
    }
}
