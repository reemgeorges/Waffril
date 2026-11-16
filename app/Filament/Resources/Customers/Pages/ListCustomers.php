<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = CustomerResource::class;
}
