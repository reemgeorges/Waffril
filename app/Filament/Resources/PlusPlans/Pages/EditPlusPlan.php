<?php

namespace App\Filament\Resources\PlusPlans\Pages;

use App\Filament\Resources\PlusPlans\PlusPlanResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditPlusPlan extends EditRecord
{
    use TranslatableRecord;

    protected static string $resource = PlusPlanResource::class;
}
