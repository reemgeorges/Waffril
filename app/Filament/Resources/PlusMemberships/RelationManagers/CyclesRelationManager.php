<?php

namespace App\Filament\Resources\PlusMemberships\RelationManagers;

use App\Filament\Resources\PlusCycles\PlusCycleResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Confirmed cycles of this membership (read-only snapshots). */
class CyclesRelationManager extends RelationManager
{
    protected static string $relationship = 'cycles';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.plus_cycle.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return PlusCycleResource::table($table)->filters([]);
    }
}
