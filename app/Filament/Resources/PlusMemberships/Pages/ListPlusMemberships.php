<?php

namespace App\Filament\Resources\PlusMemberships\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\PlusMemberships\PlusMembershipResource;
use Filament\Resources\Pages\ListRecords;

class ListPlusMemberships extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = PlusMembershipResource::class;
}
