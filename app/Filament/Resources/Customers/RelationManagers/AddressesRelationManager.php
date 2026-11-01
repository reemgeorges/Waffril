<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only customer addresses. */
class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.customers.addresses');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('label')->label(__('admin.fields.label'))->placeholder('—'),
            TextColumn::make('city.name')->label(__('admin.fields.city')),
            TextColumn::make('address_line')->label(__('admin.fields.address')),
            TextColumn::make('details')->label(__('admin.fields.details'))->placeholder('—'),
            IconColumn::make('is_default')->label(__('admin.fields.is_default'))->boolean(),
        ]);
    }
}
