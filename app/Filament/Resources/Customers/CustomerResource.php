<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\WalletsRelationManager;
use App\Filament\Resources\PlusMemberships\PlusMembershipResource;
use App\Filament\Resources\PlusRequests\PlusRequestResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\PlusStatus;
use App\Filament\Support\TableDefaults;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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

    protected static string|\UnitEnum|null $navigationGroup = 'people';

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
            Section::make(__('admin.customers.profile'))->icon('heroicon-o-user-circle')->columns(3)->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('full_name')->label(__('admin.fields.name'))->state(fn (User $record) => $record->fullName()),
                TextEntry::make('phone')->label(__('admin.fields.phone'))->placeholder('—'),
                TextEntry::make('email')->label(__('admin.fields.email'))->placeholder('—')->extraAttributes(['dir' => 'ltr', 'class' => 'wf-break']),
                TextEntry::make('email_verified_at')->label(__('admin.fields.email_verified_at'))->dateTime()->placeholder('—'),
                TextEntry::make('language')->label(__('admin.fields.language'))
                    ->formatStateUsing(fn ($state) => in_array($state, ['ar', 'en'], true) ? __("admin.languages.{$state}") : $state),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('profile_completed_at')->label(__('admin.fields.profile_completed_at'))->dateTime()->placeholder('—'),
            ]),
            Section::make(__('admin.customers.account_state'))->icon('heroicon-o-shield-check')->columns(3)->schema([
                TextEntry::make('account_status')->label(__('admin.fields.account_status'))->badge()
                    ->color(fn ($state) => $state === User::ACCOUNT_SUSPENDED ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => __("admin.customers.status_{$state}")),
                TextEntry::make('suspended_at')->label(__('admin.fields.suspended_at'))->dateTime()->placeholder('—'),
                TextEntry::make('suspension_reason')->label(__('admin.fields.suspension_reason'))->placeholder('—'),
                TextEntry::make('deleted_at')->label(__('admin.fields.deletion_requested_at'))->dateTime()->placeholder('—'),
                TextEntry::make('recovery_until')->label(__('admin.fields.recovery_until'))->dateTime()->placeholder('—'),
                TextEntry::make('anonymized_at')->label(__('admin.fields.anonymized_at'))->dateTime()->placeholder('—'),
            ]),
            // UX overhaul (2026-11-12): the customer's real PLUS situation.
            Section::make(__('admin.nav.plus'))->icon('heroicon-o-star')->columnSpanFull()->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                TextEntry::make('plus_state')->label(__('admin.fields.status'))->badge()
                    ->state(fn (User $record) => PlusStatus::for($record)['label'])
                    ->color(fn (User $record) => PlusStatus::for($record)['color'])
                    ->icon(fn (User $record) => PlusStatus::for($record)['icon']),
                TextEntry::make('plus_plan')->label(__('admin.fields.plan'))->placeholder('—')
                    ->state(fn (User $record) => PlusStatus::for($record)['plan']),
                TextEntry::make('plus_cycle')->label(__('admin.plus.current_cycle'))->placeholder('—')
                    ->state(fn (User $record) => ($c = PlusStatus::for($record)['cycle'])
                        ? $c->starts_at?->translatedFormat(TableDefaults::dateFormat()).' – '.$c->ends_at?->translatedFormat(TableDefaults::dateFormat()) : null),
                TextEntry::make('plus_free')->label(__('admin.plus.free_deliveries_remaining'))->placeholder('—')
                    ->state(fn (User $record) => ($c = PlusStatus::for($record)['cycle'])
                        ? max(0, (int) $c->free_deliveries_total - (int) $c->free_deliveries_used).' / '.(int) $c->free_deliveries_total : null),
                TextEntry::make('plus_pending')->label(__('admin.plus.pending_requests'))
                    ->state(fn (User $record) => PlusStatus::for($record)['pending'])
                    ->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->url(fn (User $record) => PlusRequestResource::canViewAny() ? PlusRequestResource::getUrl('index') : null),
                TextEntry::make('plus_membership_link')->label(__('admin.plus.membership_page'))->placeholder('—')
                    ->state(fn (User $record) => $record->plusMembership ? __('admin.actions.open') : null)
                    ->url(fn (User $record) => $record->plusMembership && PlusMembershipResource::canViewAny()
                        ? PlusMembershipResource::getUrl('view', ['record' => $record->plusMembership]) : null)
                    ->color('primary')->icon('heroicon-m-arrow-top-right-on-square'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('first_name')->label(__('admin.fields.name'))->weight(FontWeight::SemiBold)
                    ->description(fn (User $record) => $record->email)
                    ->formatStateUsing(fn ($state, User $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name', 'name']),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->searchable()->placeholder('—'),
                TextColumn::make('email')->label(__('admin.fields.email'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_status')->label(__('admin.fields.account_status'))->badge()
                    ->color(fn ($state) => $state === User::ACCOUNT_SUSPENDED ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => __("admin.customers.status_{$state}")),
                IconColumn::make('deleted_at')->label(__('admin.customers.deletion_requested'))->boolean()
                    ->visible(fn () => User::onlyTrashed()->exists())
                    ->state(fn (User $record) => $record->trashed()),
                TextColumn::make('plus')->label(__('admin.nav.plus'))->badge()
                    ->state(fn (User $record) => PlusStatus::for($record)['label'])
                    ->color(fn (User $record) => PlusStatus::for($record)['color'])
                    ->icon(fn (User $record) => PlusStatus::for($record)['icon'])
                    ->description(fn (User $record) => ($p = PlusStatus::for($record))['state'] === PlusStatus::NONE ? null
                        : collect([$p['plan'], $p['cycle']?->ends_at ? __('admin.plus.until', ['date' => $p['cycle']->ends_at->translatedFormat(TableDefaults::dateFormat())]) : null])->filter()->implode(' · ')),
                TextColumn::make('orders_count')->counts('orders')->label(__('admin.resources.order.plural'))->sortable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_status')->label(__('admin.fields.account_status'))->options([
                    User::ACCOUNT_ACTIVE => __('admin.customers.status_active'),
                    User::ACCOUNT_SUSPENDED => __('admin.customers.status_suspended'),
                ]),
                SelectFilter::make('plus')->label(__('admin.nav.plus'))
                    ->options([
                        PlusStatus::ACTIVE => __('admin.plus_status.active'),
                        PlusStatus::PENDING => __('admin.plus_status.pending'),
                        PlusStatus::NONE => __('admin.plus_status.none'),
                    ])
                    ->query(fn (Builder $query, array $data) => PlusStatus::scope($query, $data['value'] ?? null)),
                TrashedFilter::make()->label(__('admin.customers.deletion_requested')),
            ])
            ->modifyQueryUsing(fn (Builder $query) => PlusStatus::eagerLoad($query))
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        // Everything about one customer in one place (UX overhaul 2026-11-12).
        return [
            'orders' => OrdersRelationManager::class,
            'wallets' => WalletsRelationManager::class,
            'addresses' => AddressesRelationManager::class,
            'plus' => RelationManagers\PlusRequestsRelationManager::class,
            'ratings' => RelationManagers\RatingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
