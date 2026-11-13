<?php

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\RelationManagers\BranchProductsRelationManager;
use App\Filament\Resources\Branches\RelationManagers\HourExceptionsRelationManager;
use App\Filament\Resources\Branches\RelationManagers\OpeningHoursRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Branch;
use App\Services\Catalog\CatalogAdminService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Branches: city, location, weekly hours, special
 * openings/closures, per-branch product prices and availability. Delivery
 * fees/coverage are platform-wide rules, never branch settings. A branch's
 * store cannot change after creation (its product prices depend on it).
 */
class BranchResource extends AdminResource
{
    protected static ?string $model = Branch::class;

    /**
     * UX overhaul (2026-11-12): not in the sidebar. Managed from its owning
     * workflow (Stores → store → Branches / Products / categories / ratings);
     * the pages and routes stay, protected by the same policy.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $labelKey = 'branch';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 20;

    /**
     * UX redesign (2026-11-11): grouped by what the employee is doing (which
     * store and city → where it is → how it takes orders). Opened from a
     * store ("Add branch"), the store is pre-selected.
     */
    public static function form(Schema $schema): Schema
    {
        $label = fn ($record) => $record->name;

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Group::make()->columnSpan(['default' => 1, 'lg' => 2])->schema([
                    Section::make(__('admin.branches.section_info'))->icon('heroicon-o-building-office-2')->schema([
                        Grid::make(2)->schema([
                            Select::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                                ->getOptionLabelFromRecordUsing($label)->required()->searchable()->preload()
                                ->default(fn () => request()->integer('store') ?: null)
                                // Inside Store → Branches/Products the store is the owner record.
                                ->hidden(fn ($livewire) => $livewire instanceof RelationManager)
                                ->disabled(fn (?Branch $record) => $record !== null)->dehydrated(fn (?Branch $record) => $record === null),
                            Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                                ->getOptionLabelFromRecordUsing($label)->required()->searchable()->preload(),
                        ]),
                        Fields::translatable('name'),
                        Fields::translatable('address', multiline: true),
                    ]),
                    Section::make(__('admin.branches.section_location'))->icon('heroicon-o-map-pin')
                        ->description(__('admin.branches.section_location_help'))
                        ->columns(3)->schema([
                            TextInput::make('lat')->label(__('admin.fields.lat'))->required()->numeric()->minValue(-90)->maxValue(90)->extraInputAttributes(['dir' => 'ltr']),
                            TextInput::make('lng')->label(__('admin.fields.lng'))->required()->numeric()->minValue(-180)->maxValue(180)->extraInputAttributes(['dir' => 'ltr']),
                            Fields::phone('phone'),
                        ]),
                ]),
                Group::make()->columnSpan(['default' => 1, 'lg' => 1])->schema([
                    Section::make(__('admin.branches.section_orders'))->icon('heroicon-o-shopping-bag')->schema([
                        Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)
                            ->helperText(__('admin.help.branch_active')),
                        Toggle::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))->default(true)
                            ->helperText(__('admin.help.branch_accepting')),
                        Fields::money('min_order_amount', required: false)->helperText(__('admin.help.default_currency_amount')),
                    ]),
                ]),
            ]),
        ]);
    }

    /**
     * All branches (opened from Stores; table UX overhaul 2026-11-12): name
     * with its store and city, whether it takes orders, priced products and
     * status. "Prices & availability" is the primary action; pausing orders
     * keeps its audited service and confirmation.
     */
    public static function table(Table $table): Table
    {
        $edit = fn (Branch $record, ?string $tab = null) => static::getUrl('edit', ['record' => $record]).($tab ? '?relation='.$tab : '');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['store', 'city']))
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn (Branch $record) => collect([$record->store?->name, $record->city?->name])->filter()->implode(' · ')),
                TextColumn::make('orders_state')->label(__('admin.fields.is_accepting_orders'))->badge()
                    ->state(fn (Branch $record) => ! $record->is_active || $record->trashed() ? 'inactive' : ($record->is_accepting_orders ? 'accepting' : 'paused'))
                    ->formatStateUsing(fn (string $state) => __('admin.table.'.$state))
                    ->icon(fn (string $state) => match ($state) {
                        'accepting' => 'heroicon-m-check-circle',
                        'paused' => 'heroicon-m-pause-circle',
                        default => 'heroicon-m-no-symbol',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'accepting' => 'success',
                        'paused' => 'warning',
                        default => 'gray',
                    })->width(TableDefaults::COMPACT_WIDTH),
                Columns::countLink('branch_products_count', __('admin.stores.priced_products'), 'admin.table.priced_products_count', 'heroicon-m-cube',
                    fn (Branch $record) => $edit($record, 'products'))->counts('branchProducts')->visibleFrom('md'),
                Columns::status(),
            ])
            ->searchPlaceholder(__('admin.table.search_branches'))
            ->filters([
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->searchable()->preload(),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->preload(),
                TernaryFilter::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))
                    ->trueLabel(__('admin.table.accepting'))->falseLabel(__('admin.table.paused')),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(Action::make('manage')->label(__('admin.table.prices_and_availability'))->icon('heroicon-m-currency-dollar')
                    ->url(fn (Branch $record) => $edit($record, 'products'))
                    ->visible(fn (Branch $record) => static::can('update', $record))),
                RowActions::more([
                    EditAction::make()->label(__('admin.table.edit_details')),
                    Action::make('hours')->label(__('admin.table.opening_hours'))->icon('heroicon-m-clock')
                        ->url(fn (Branch $record) => $edit($record, 'hours')),
                    Action::make('toggleAccepting')
                        ->label(fn (Branch $record) => $record->is_accepting_orders ? __('admin.branches.pause_orders') : __('admin.branches.resume_orders'))
                        ->icon(fn (Branch $record) => $record->is_accepting_orders ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
                        ->color(fn (Branch $record) => $record->is_accepting_orders ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->visible(fn (Branch $record) => static::can('update', $record) && ! $record->trashed())
                        ->action(fn (Branch $record) => BusinessAction::run(
                            fn ($admin) => app(CatalogAdminService::class)->setBranchAcceptingOrders($record, ! $record->is_accepting_orders, $admin)
                        )),
                    ...RowActions::activation(static::class),
                    DeleteAction::make(), RestoreAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        // Products and prices first (?relation=products), then hours.
        return [
            'products' => BranchProductsRelationManager::class,
            'hours' => OpeningHoursRelationManager::class,
            'exceptions' => HourExceptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
