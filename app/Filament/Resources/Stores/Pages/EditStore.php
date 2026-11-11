<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Filament\Concerns\CombinesRelationTabs;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    use CombinesRelationTabs;
    use TranslatableRecord;

    protected static string $contentTabLabelKey = 'admin.stores.section_info';

    protected static ?string $contentTabIcon = 'heroicon-o-building-storefront';

    protected static string $resource = StoreResource::class;
}
