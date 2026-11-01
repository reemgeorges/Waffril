<?php

namespace App\Filament\Resources\Wallets;

use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Filament\Resources\Wallets\Pages\ViewWallet;
use App\Filament\Resources\Wallets\RelationManagers\TransactionsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Wallet;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Wallet balances per customer and currency —
 * READ-ONLY. Balances change only through the append-only ledger (order
 * payments, reversals, refunds); there is no edit, top-up, withdrawal or
 * manual credit/debit action anywhere in the panel.
 */
class WalletResource extends AdminResource
{
    protected static ?string $model = Wallet::class;

    protected static string $labelKey = 'wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, Wallet $record) => $record->user?->fullName().' '.$state),
                TextEntry::make('currency.code')->label(__('admin.fields.currency')),
                TextEntry::make('balance')->label(__('admin.fields.balance'))->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextEntry::make('updated_at')->label(__('admin.fields.updated_at'))->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.phone')->label(__('admin.fields.customer'))
                    ->formatStateUsing(fn ($state, Wallet $record) => $record->user?->fullName().' '.$state)->searchable(),
                TextColumn::make('currency.code')->label(__('admin.fields.currency')),
                TextColumn::make('balance')->label(__('admin.fields.balance'))->sortable()->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency)),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('currency_id')->label(__('admin.fields.currency'))->relationship('currency', 'code'),
                Filter::make('nonzero')->label(__('admin.wallets.nonzero'))->query(fn ($query) => $query->where('balance', '>', 0)),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListWallets::route('/'), 'view' => ViewWallet::route('/{record}')];
    }
}
