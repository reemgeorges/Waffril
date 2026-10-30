<?php

namespace App\Filament\Resources\OrderRefunds\Pages;

use App\Filament\Resources\OrderRefunds\OrderRefundResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderRefunds extends ListRecords
{
    protected static string $resource = OrderRefundResource::class;
}
