<?php

namespace App\Filament\Resources\ErrorEvents\Pages;

use App\Filament\Resources\ErrorEvents\ErrorEventResource;
use Filament\Resources\Pages\ListRecords;

class ListErrorEvents extends ListRecords
{
    protected static string $resource = ErrorEventResource::class;
}
