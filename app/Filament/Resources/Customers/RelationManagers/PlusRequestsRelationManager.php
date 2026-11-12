<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\PlusRequests\PlusRequestResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Customer → PLUS history (UX overhaul 2026-11-12): every PLUS request of
 * this customer with its review, and the same approve / reject actions and
 * permissions as the PLUS requests page.
 */
class PlusRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'plusRequests';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-star';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.history');
    }

    public function table(Table $table): Table
    {
        return PlusRequestResource::table($table)->filters([]);
    }
}
