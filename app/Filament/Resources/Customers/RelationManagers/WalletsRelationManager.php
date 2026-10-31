<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Wallets\WalletResource;
use App\Filament\Support\Fields;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only wallet balances per currency (never editable). */
class WalletsRelationManager extends RelationManager
{
    protected static string $relationship = 'wallets';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.wallet.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency.code')->label(__('admin.fields.currency')),
                TextColumn::make('balance')->label(__('admin.fields.balance'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->dateTime(),
            ])
            ->recordActions([
                Action::make('history')->label(__('admin.wallets.history'))->icon('heroicon-o-list-bullet')
                    ->url(fn ($record) => WalletResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
