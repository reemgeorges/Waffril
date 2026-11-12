<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Concerns\UnderStores;
use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranches extends ListRecords
{
    use UnderStores;

    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->backToStoresAction(), CreateAction::make()];
    }
}
