<?php

namespace App\Filament\Resources\HomeSliders\Pages;

use App\Filament\Resources\HomeSliders\HomeSliderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHomeSliders extends ManageRecords
{
    protected static string $resource = HomeSliderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
