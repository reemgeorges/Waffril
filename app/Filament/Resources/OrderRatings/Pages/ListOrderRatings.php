<?php

namespace App\Filament\Resources\OrderRatings\Pages;

use App\Filament\Resources\OrderRatings\OrderRatingResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderRatings extends ListRecords
{
    protected static string $resource = OrderRatingResource::class;
}
