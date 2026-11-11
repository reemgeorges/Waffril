<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Concerns\CombinesRelationTabs;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    use CombinesRelationTabs;
    use TranslatableRecord;

    protected static string $contentTabLabelKey = 'admin.branches.section_info';

    protected static ?string $contentTabIcon = 'heroicon-o-building-office-2';

    protected static string $resource = BranchResource::class;
}
