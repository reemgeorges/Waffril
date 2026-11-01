<?php

namespace App\Filament\Resources\StoreCategories\Pages;

use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStoreCategories extends ManageRecords
{
    protected static string $resource = StoreCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
