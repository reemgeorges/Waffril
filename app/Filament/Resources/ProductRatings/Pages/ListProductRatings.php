<?php

namespace App\Filament\Resources\ProductRatings\Pages;

use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\Pages\ListRecords;

class ListProductRatings extends ListRecords
{
    protected static string $resource = ProductRatingResource::class;
}
