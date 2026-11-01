<?php

namespace App\Filament\Resources\OrderPayments\Pages;

use App\Filament\Resources\OrderPayments\OrderPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderPayments extends ListRecords
{
    protected static string $resource = OrderPaymentResource::class;
}
