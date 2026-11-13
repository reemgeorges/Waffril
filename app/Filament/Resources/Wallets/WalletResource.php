<?php

namespace App\Filament\Resources\Wallets;

use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Filament\Resources\Wallets\Pages\ViewWallet;
use App\Filament\Resources\Wallets\RelationManagers\TransactionsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Wallet;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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

    protected static ?int $navigationSort = 40;

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

    /**
     * Wallets (table UX overhaul 2026-11-12): the customer with phone, the
     * wallet currency and its balance in that currency (never converted),
     * last movement. "Movements" opens the wallet history.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'currency']))
            ->columns([
                TextColumn::make('user.first_name')->label(__('admin.fields.customer'))->weight(FontWeight::SemiBold)
                    ->state(fn (Wallet $record) => $record->user?->fullName())
                    ->description(fn (Wallet $record) => $record->user?->phone)
                    ->searchable(query: Fields::searchCustomer('user')),
                TextColumn::make('currency.code')->label(__('admin.fields.currency'))->badge()->color('gray'),
                TextColumn::make('balance')->label(__('admin.fields.balance'))->sortable()->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, $record) => Fields::formatMoney($state, $record->currency))
                    ->color(fn ($state) => (float) $state > 0 ? null : 'gray'),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->since()->sortable()->color('gray')
                    ->tooltip(fn ($record) => $record->updated_at?->translatedFormat(TableDefaults::dateTimeFormat()))->visibleFrom('md'),
            ])
            ->searchPlaceholder(__('admin.table.search_wallets'))
            ->filters([
                SelectFilter::make('currency_id')->label(__('admin.fields.currency'))->relationship('currency', 'code'),
                Filter::make('nonzero')->label(__('admin.wallets.nonzero'))->toggle()->query(fn ($query) => $query->where('balance', '>', 0)),
            ])
            ->recordActions([RowActions::primary(ViewAction::make()->label(__('admin.table.history'))->icon('heroicon-m-list-bullet'))]);
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
