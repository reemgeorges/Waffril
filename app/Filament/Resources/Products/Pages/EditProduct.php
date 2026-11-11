<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\CombinesRelationTabs;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\TranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use CombinesRelationTabs;
    use TranslatableRecord;

    protected static string $contentTabLabelKey = 'admin.products.section_info';

    protected static ?string $contentTabIcon = 'heroicon-o-cube';

    protected static string $resource = ProductResource::class;
}
