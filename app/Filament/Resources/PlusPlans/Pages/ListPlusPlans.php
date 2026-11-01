<?php

namespace App\Filament\Resources\PlusPlans\Pages;

use App\Filament\Resources\PlusPlans\PlusPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlusPlans extends ListRecords
{
    protected static string $resource = PlusPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
