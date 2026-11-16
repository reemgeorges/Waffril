<?php

namespace App\Filament\Resources\PlusCycles\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\PlusCycles\PlusCycleResource;
use Filament\Resources\Pages\ListRecords;

class ListPlusCycles extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = PlusCycleResource::class;
}
