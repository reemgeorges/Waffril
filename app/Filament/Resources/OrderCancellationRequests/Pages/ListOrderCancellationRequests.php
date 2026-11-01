<?php

namespace App\Filament\Resources\OrderCancellationRequests\Pages;

use App\Filament\Resources\OrderCancellationRequests\OrderCancellationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderCancellationRequests extends ListRecords
{
    use \App\Filament\Concerns\RefreshesOnOrderEvents;

    protected static string $resource = OrderCancellationRequestResource::class;
}
