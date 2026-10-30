<?php

namespace App\Filament\Resources\WalletTransactions;

use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Support\AdminResource;
use App\Models\WalletTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). The append-only wallet ledger — read-only.
 */
class WalletTransactionResource extends AdminResource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string $labelKey = 'wallet_transaction';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('wallet.user.phone')->label(__('admin.fields.customer'))->placeholder('—'),
                TextColumn::make('wallet.currency.code')->label(__('admin.fields.currency')),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()->formatStateUsing(fn ($state) => __("admin.wallets.type_{$state}")),
                TextColumn::make('amount')->label(__('admin.fields.amount'))
                    ->formatStateUsing(fn ($state, WalletTransaction $record) => ($record->direction === 'debit' ? '−' : '+').$state)
                    ->color(fn (WalletTransaction $record) => $record->direction === 'debit' ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label(__('admin.fields.balance_after')),
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.fields.type'))->options([
                    WalletTransaction::TYPE_ORDER_PAYMENT => __('admin.wallets.type_order_payment'),
                    WalletTransaction::TYPE_ORDER_PAYMENT_REVERSAL => __('admin.wallets.type_order_payment_reversal'),
                    WalletTransaction::TYPE_ORDER_REFUND => __('admin.wallets.type_order_refund'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListWalletTransactions::route('/')];
    }
}
