<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    use TranslatableRecord;

    protected static string $resource = BranchResource::class;
}
