<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Resources\DriverCashSettlements\DriverCashSettlementResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Daily cash settlements of this driver. */
class CashSettlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'cashSettlements';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.driver_cash_settlement.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return DriverCashSettlementResource::table($table);
    }
}
