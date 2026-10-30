<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Cash this driver actually collected (read-only). */
class CashCollectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'collectedPayments';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.drivers.cash_collections');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number')),
                TextColumn::make('collected_amount')->label(__('admin.fields.collected_amount'))
                    ->formatStateUsing(fn ($state, $record) => $state.' '.$record->currency?->code),
                TextColumn::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime()->sortable(),
                TextColumn::make('settlement.business_date')->label(__('admin.fields.business_date'))->date()->placeholder(__('admin.drivers.unsettled')),
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
