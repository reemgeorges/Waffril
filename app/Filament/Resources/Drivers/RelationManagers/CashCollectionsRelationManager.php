<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\Fields;
use App\Filament\Support\TableDefaults;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Cash this driver actually collected (read-only). */
class CashCollectionsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'cash_collection';

    protected static string $relationship = 'collectedPayments';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.drivers.cash_collections');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Driver → Cash collections (table UX overhaul 2026-11-12): the order,
     * the cash amount in its currency, when it was paid, and the settlement
     * day it belongs to (or "Unsettled"). Read only.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'currency', 'settlement']))
            ->columns([
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => $record->paid_at?->translatedFormat(TableDefaults::dateTimeFormat())),
                TextColumn::make('collected_amount')->label(__('admin.fields.collected_amount'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('settlement.business_date')->label(__('admin.fields.business_date'))->date()->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning')
                    ->placeholder(__('admin.drivers.unsettled')),
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
