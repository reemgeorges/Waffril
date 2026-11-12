<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Concerns\InStoreContext;
use App\Filament\Resources\Branches\BranchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    use InStoreContext;

    protected static function storeTab(): string
    {
        return 'branches';
    }

    protected function getHeaderActions(): array
    {
        return [$this->backToStoreAction()];
    }

    protected static string $resource = BranchResource::class;
}
