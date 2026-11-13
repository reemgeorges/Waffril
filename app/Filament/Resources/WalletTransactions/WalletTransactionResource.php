<?php

namespace App\Filament\Resources\WalletTransactions;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Wallets\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Wallets\WalletResource;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\WalletTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WAFFRLI (2026-10-31). The append-only wallet ledger — read-only.
 */
class WalletTransactionResource extends AdminResource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navParent = WalletResource::class;

    protected static string $labelKey = 'wallet_transaction';

    protected static string|\UnitEnum|null $navigationGroup = 'finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 50;

    /**
     * Wallet movements (table UX overhaul 2026-11-12; also a wallet's
     * history): when, the customer, what happened (type), the signed amount
     * in the wallet's currency with the balance after it, and the order.
     * Read only: this is the audit trail of the wallet.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['wallet.user', 'wallet.currency', 'order']))
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable()
                    ->description(fn ($record) => '#'.$record->id),
                TextColumn::make('wallet.user.phone')->label(__('admin.fields.customer'))->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $record->wallet?->user?->fullName() ?? $state)->description(fn ($record) => $record->wallet?->user?->phone)
                    ->searchable(query: Fields::searchCustomer('wallet.user'))
                    ->hidden(fn ($livewire) => $livewire instanceof TransactionsRelationManager),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.wallets.type_{$state}"))->visibleFrom('md'),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, WalletTransaction $record) => ($record->direction === 'debit' ? '−' : '+').Fields::formatMoney($state, $record->wallet?->currency))
                    ->color(fn (WalletTransaction $record) => $record->direction === 'debit' ? 'danger' : 'success')
                    ->description(fn (WalletTransaction $record) => __('admin.table.balance_after_note', ['amount' => Fields::formatMoney($record->balance_after, $record->wallet?->currency)])),
                TextColumn::make('order.order_number')->label(__('admin.fields.order_number'))->placeholder('—')
                    ->url(fn ($record) => $record->order_id && OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%"))),
            ])
            ->searchPlaceholder(__('admin.table.search_movements'))
            ->filters([
                SelectFilter::make('type')->label(__('admin.fields.type'))->options([
                    WalletTransaction::TYPE_ORDER_PAYMENT => __('admin.wallets.type_order_payment'),
                    WalletTransaction::TYPE_ORDER_PAYMENT_REVERSAL => __('admin.wallets.type_order_payment_reversal'),
                    WalletTransaction::TYPE_ORDER_REFUND => __('admin.wallets.type_order_refund'),
                ])->multiple(),
                SelectFilter::make('direction')->label(__('admin.table.direction'))->options([
                    'credit' => __('admin.table.credit'),
                    'debit' => __('admin.table.debit'),
                ]),
                Filter::make('created_between')->label(__('admin.table.date_between'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.fields.from')),
                        DatePicker::make('until')->label(__('admin.fields.until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListWalletTransactions::route('/')];
    }
}
