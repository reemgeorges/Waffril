<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\WalletsRelationManager;
use App\Filament\Support\AdminResource;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Customers (users table). Admins can view
 * profiles, addresses, orders and wallet balances/history, and suspend,
 * reactivate or (when no obligation remains) finally anonymize an
 * account. Customers are never created or edited here; passwords, tokens
 * and OTP codes are never displayed; wallet balances cannot be edited.
 */
class CustomerResource extends AdminResource
{
    protected static ?string $model = User::class;

    protected static string $labelKey = 'customer';

    protected static string|\UnitEnum|null $navigationGroup = 'customers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'customers';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.customers.profile'))->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('full_name')->label(__('admin.fields.name'))->state(fn (User $record) => $record->fullName()),
                TextEntry::make('phone')->label(__('admin.fields.phone'))->placeholder('—'),
                TextEntry::make('email')->label(__('admin.fields.email'))->placeholder('—'),
                TextEntry::make('email_verified_at')->label(__('admin.fields.email_verified_at'))->dateTime()->placeholder('—'),
                TextEntry::make('language')->label(__('admin.fields.language')),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('profile_completed_at')->label(__('admin.fields.profile_completed_at'))->dateTime()->placeholder('—'),
            ]),
            Section::make(__('admin.customers.account_state'))->columns(3)->schema([
                TextEntry::make('account_status')->label(__('admin.fields.account_status'))->badge()
                    ->color(fn ($state) => $state === User::ACCOUNT_SUSPENDED ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => __("admin.customers.status_{$state}")),
                TextEntry::make('suspended_at')->label(__('admin.fields.suspended_at'))->dateTime()->placeholder('—'),
                TextEntry::make('suspension_reason')->label(__('admin.fields.suspension_reason'))->placeholder('—'),
                TextEntry::make('deleted_at')->label(__('admin.fields.deletion_requested_at'))->dateTime()->placeholder('—'),
                TextEntry::make('recovery_until')->label(__('admin.fields.recovery_until'))->dateTime()->placeholder('—'),
                TextEntry::make('anonymized_at')->label(__('admin.fields.anonymized_at'))->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('first_name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn ($state, User $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name', 'name']),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->searchable()->placeholder('—'),
                TextColumn::make('email')->label(__('admin.fields.email'))->searchable()->toggleable(),
                TextColumn::make('account_status')->label(__('admin.fields.account_status'))->badge()
                    ->color(fn ($state) => $state === User::ACCOUNT_SUSPENDED ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => __("admin.customers.status_{$state}")),
                IconColumn::make('deleted_at')->label(__('admin.customers.deletion_requested'))->boolean()
                    ->state(fn (User $record) => $record->trashed()),
                TextColumn::make('orders_count')->counts('orders')->label(__('admin.resources.order.plural'))->sortable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_status')->label(__('admin.fields.account_status'))->options([
                    User::ACCOUNT_ACTIVE => __('admin.customers.status_active'),
                    User::ACCOUNT_SUSPENDED => __('admin.customers.status_suspended'),
                ]),
                TrashedFilter::make()->label(__('admin.customers.deletion_requested')),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [AddressesRelationManager::class, OrdersRelationManager::class, WalletsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
