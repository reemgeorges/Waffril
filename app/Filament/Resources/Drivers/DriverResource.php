<?php

namespace App\Filament\Resources\Drivers;

use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\RelationManagers\CashCollectionsRelationManager;
use App\Filament\Resources\Drivers\RelationManagers\CashSettlementsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Fields;
use App\Models\Driver;
use App\Services\Driver\DriverAdminService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Platform drivers. Accounts are created by
 * admins; activation, deactivation and forcing offline go through
 * DriverAdminService (refused while a delivery is in progress).
 * Availability is otherwise set by the driver app.
 */
class DriverResource extends AdminResource
{
    protected static ?string $model = Driver::class;

    protected static string $labelKey = 'driver';

    protected static string|\UnitEnum|null $navigationGroup = 'drivers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                    Fields::phone('phone')->required()->unique(ignoreRecord: true),
                    TextInput::make('password')->label(__('admin.fields.password'))->password()->revealable()
                        ->minLength(8)->maxLength(100)
                        ->required(fn (?Driver $record) => $record === null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(fn (?Driver $record) => $record ? __('admin.help.password_keep') : null),
                    Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->preload(),
                    TextInput::make('vehicle_type')->label(__('admin.fields.vehicle_type'))->maxLength(30),
                    TextInput::make('vehicle_plate')->label(__('admin.fields.vehicle_plate'))->maxLength(30),
                    Select::make('language')->label(__('admin.fields.language'))->options(['ar' => 'العربية', 'en' => 'English'])->default('ar')->required(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->searchable(),
                TextColumn::make('city.name')->label(__('admin.fields.city')),
                TextColumn::make('availability')->label(__('admin.fields.availability'))->badge()
                    ->color(fn ($state) => $state === Driver::AVAILABILITY_AVAILABLE ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => __("admin.drivers.availability_{$state}")),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                TextColumn::make('active_orders_count')->counts('activeOrders')->label(__('admin.drivers.active_deliveries')),
                TextColumn::make('last_location_at')->label(__('admin.fields.last_location_at'))->since()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('availability')->label(__('admin.fields.availability'))->options([
                    Driver::AVAILABILITY_AVAILABLE => __('admin.drivers.availability_available'),
                    Driver::AVAILABILITY_OFFLINE => __('admin.drivers.availability_offline'),
                ]),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('activate')->label(__('admin.actions.activate'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Driver $record) => ! $record->is_active && static::can('update', $record))->requiresConfirmation()
                    ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->setActive($record, true, $admin))),
                Action::make('deactivate')->label(__('admin.actions.deactivate'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Driver $record) => $record->is_active && static::can('update', $record))->requiresConfirmation()
                    ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->setActive($record, false, $admin))),
                Action::make('forceOffline')->label(__('admin.drivers.force_offline'))->icon('heroicon-o-signal-slash')
                    ->visible(fn (Driver $record) => $record->availability === Driver::AVAILABILITY_AVAILABLE && static::can('update', $record))->requiresConfirmation()
                    ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->forceOffline($record, $admin))),
            ]);
    }

    public static function getRelations(): array
    {
        return [OrdersRelationManager::class, CashCollectionsRelationManager::class, CashSettlementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }
}
