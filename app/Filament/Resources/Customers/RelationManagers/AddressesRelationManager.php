<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only customer addresses. */
class AddressesRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'address';

    protected static string $relationship = 'addresses';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.customers.addresses');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Customer → Addresses (table UX overhaul 2026-11-12): label and city,
     * the address with its details, and which one is the default. Read only.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('city'))
            ->columns([
                TextColumn::make('label')->label(__('admin.fields.label'))->weight(FontWeight::SemiBold)->placeholder('—')
                    ->description(fn ($record) => $record->city?->name),
                TextColumn::make('address_line')->label(__('admin.fields.address'))->wrap()
                    ->description(fn ($record) => $record->details),
                TextColumn::make('is_default')->label(__('admin.fields.is_default'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('admin.table.default_address') : null)
                    ->icon('heroicon-m-star')->color('primary')->placeholder(null),
            ])
            ->defaultSort('is_default', 'desc');
    }
}
