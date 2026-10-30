<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    use \App\Filament\Concerns\RefreshesOnOrderEvents;

    protected static string $resource = OrderResource::class;
}
