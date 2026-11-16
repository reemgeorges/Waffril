<?php

namespace App\Filament\Resources\OrderPayments\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\OrderPayments\OrderPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderPayments extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = OrderPaymentResource::class;
}
