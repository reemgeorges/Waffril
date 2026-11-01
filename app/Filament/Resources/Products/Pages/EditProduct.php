<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use TranslatableRecord;

    protected static string $resource = ProductResource::class;
}
