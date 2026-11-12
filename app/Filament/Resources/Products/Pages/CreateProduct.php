<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InStoreContext;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use InStoreContext;

    protected static function storeTab(): string
    {
        return 'products';
    }

    protected function getHeaderActions(): array
    {
        return [$this->backToStoreAction()];
    }

    protected static string $resource = ProductResource::class;
}
