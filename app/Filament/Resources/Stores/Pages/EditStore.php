<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    use TranslatableRecord;

    protected static string $resource = StoreResource::class;
}
