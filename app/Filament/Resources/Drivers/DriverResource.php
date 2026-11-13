<?php

namespace App\Filament\Resources\Drivers;

use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\RelationManagers\CashCollectionsRelationManager;
use App\Filament\Resources\Drivers\RelationManagers\CashSettlementsRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Driver;
use App\Services\Driver\DriverAdminService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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

    protected static string|\UnitEnum|null $navigationGroup = 'people';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // UX redesign round 2: who the driver is, how they sign in, where
            // and with what they work. Same fields and rules as before.
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make(__('admin.drivers.section_person'))->icon('heroicon-o-identification')->columnSpan(['default' => 1, 'lg' => 2])->columns(2)->schema([
                    TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                    Fields::phone('phone')->required()->unique(ignoreRecord: true),
                    Select::make('language')->label(__('admin.fields.language'))->options(['ar' => 'العربية', 'en' => 'English'])->default('ar')->required()
                        ->helperText(__('admin.help.driver_language')),
                    TextInput::make('password')->label(__('admin.fields.password'))->password()->revealable()
                        ->minLength(8)->maxLength(100)
                        ->required(fn (?Driver $record) => $record === null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(fn (?Driver $record) => $record ? __('admin.help.password_keep') : __('admin.help.driver_password')),
                ]),
                Section::make(__('admin.drivers.section_work'))->icon('heroicon-o-truck')->columnSpan(['default' => 1, 'lg' => 1])->schema([
                    Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->preload()
                        ->helperText(__('admin.help.driver_city')),
                    TextInput::make('vehicle_type')->label(__('admin.fields.vehicle_type'))->maxLength(30),
                    TextInput::make('vehicle_plate')->label(__('admin.fields.vehicle_plate'))->maxLength(30)->extraInputAttributes(['dir' => 'ltr']),
                ]),
            ]),
        ]);
    }

    /**
     * Drivers (table UX overhaul 2026-11-12): who (name, vehicle, phone),
     * where (city), whether they can take work now (availability and
     * account status), what they carry now (active deliveries, a link to
     * those orders) and when their position was last seen. Manage is the
     * primary action; activation and "force offline" (audited services)
     * sit under "More actions" with their confirmation.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('city'))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn ($record) => trim(($record->vehicle_type ?? '').' '.($record->vehicle_plate ?? '')) ?: null),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->searchable()->icon('heroicon-m-phone')->color('gray')->visibleFrom('md'),
                TextColumn::make('city.name')->label(__('admin.fields.city'))->visibleFrom('lg'),
                TextColumn::make('availability')->label(__('admin.fields.availability'))->badge()
                    ->icon(fn ($state) => $state === 'available' ? 'heroicon-m-signal' : 'heroicon-m-moon')
                    ->color(fn ($state) => $state === Driver::AVAILABILITY_AVAILABLE ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => __("admin.drivers.availability_{$state}")),
                Columns::countLink('active_orders_count', __('admin.drivers.active_deliveries'), 'admin.table.deliveries_count', 'heroicon-m-truck',
                    fn (Driver $record) => (int) $record->active_orders_count > 0 && OrderResource::canViewAny()
                        ? OrderResource::getUrl('index', ['tab' => 'on_the_road', 'filters' => ['driver_id' => ['value' => $record->getKey()]]]) : null,
                    __('admin.table.open_driver_orders'))->counts('activeOrders'),
                Columns::status(),
                TextColumn::make('last_location_at')->label(__('admin.table.last_seen'))->since()->placeholder('—')->sortable()
                    ->tooltip(fn ($record) => $record->last_location_at?->translatedFormat(TableDefaults::dateTimeFormat()))
                    ->visibleFrom('lg'),
            ])
            ->searchPlaceholder(__('admin.table.search_drivers'))
            ->filters([
                SelectFilter::make('availability')->label(__('admin.fields.availability'))->options([
                    Driver::AVAILABILITY_AVAILABLE => __('admin.drivers.availability_available'),
                    Driver::AVAILABILITY_OFFLINE => __('admin.drivers.availability_offline'),
                ]),
                TernaryFilter::make('on_delivery')->label(__('admin.table.delivery_state'))
                    ->trueLabel(__('admin.table.on_delivery'))->falseLabel(__('admin.table.unassigned'))
                    ->queries(true: fn ($query) => $query->has('activeOrders'), false: fn ($query) => $query->doesntHave('activeOrders')),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.manage_driver'))->icon('heroicon-m-cog-6-tooth')),
                RowActions::more([
                    Action::make('activate')->label(__('admin.actions.activate'))->icon('heroicon-m-check-circle')->color('success')
                        ->visible(fn (Driver $record) => ! $record->is_active && static::can('update', $record))->requiresConfirmation()
                        ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->setActive($record, true, $admin))),
                    Action::make('deactivate')->label(__('admin.actions.deactivate'))->icon('heroicon-m-x-circle')->color('danger')
                        ->visible(fn (Driver $record) => $record->is_active && static::can('update', $record))->requiresConfirmation()
                        ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->setActive($record, false, $admin))),
                    Action::make('forceOffline')->label(__('admin.drivers.force_offline'))->icon('heroicon-m-signal-slash')->color('warning')
                        ->visible(fn (Driver $record) => $record->availability === Driver::AVAILABILITY_AVAILABLE && static::can('update', $record))->requiresConfirmation()
                        ->action(fn (Driver $record) => BusinessAction::run(fn ($admin) => app(DriverAdminService::class)->forceOffline($record, $admin))),
                ]),
            ])
            ->defaultSort('name');
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
