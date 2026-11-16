<?php

namespace App\Filament\Resources\OrderRefunds\Pages;

use App\Filament\Concerns\RefreshesOnOperationalEvents;
use App\Filament\Resources\OrderRefunds\OrderRefundResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderRefunds extends ListRecords
{
    use RefreshesOnOperationalEvents;

    protected static string $resource = OrderRefundResource::class;
}
