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
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Services\Catalog\CatalogAdminService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    protected static string $labelKey = 'branch';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        $label = fn ($record) => $record->name;

        return $schema->components([
            Section::make()->schema([
                Grid::make(2)->schema([
                    Select::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                        ->getOptionLabelFromRecordUsing($label)->required()->searchable()->preload()
                        ->disabled(fn (?Branch $record) => $record !== null)->dehydrated(fn (?Branch $record) => $record === null),
                    Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                        ->getOptionLabelFromRecordUsing($label)->required()->searchable()->preload(),
                ]),
                Fields::translatable('name'),
                Fields::translatable('address', multiline: true),
                Grid::make(3)->schema([
                    TextInput::make('lat')->label(__('admin.fields.lat'))->required()->numeric()->minValue(-90)->maxValue(90),
                    TextInput::make('lng')->label(__('admin.fields.lng'))->required()->numeric()->minValue(-180)->maxValue(180),
                    Fields::phone('phone'),
                ]),
                Fields::money('min_order_amount', required: false)->helperText(__('admin.help.default_currency_amount')),
                Grid::make(2)->schema([
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
                    Toggle::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))->default(true),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('store.name')->label(__('admin.fields.store')),
                TextColumn::make('city.name')->label(__('admin.fields.city')),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                IconColumn::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                TernaryFilter::make('is_accepting_orders')->label(__('admin.fields.is_accepting_orders')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('toggleAccepting')
                    ->label(fn (Branch $record) => $record->is_accepting_orders ? __('admin.branches.pause_orders') : __('admin.branches.resume_orders'))
                    ->icon('heroicon-o-pause-circle')->requiresConfirmation()
                    ->visible(fn (Branch $record) => static::can('update', $record) && ! $record->trashed())
                    ->action(fn (Branch $record) => BusinessAction::run(
                        fn ($admin) => app(CatalogAdminService::class)->setBranchAcceptingOrders($record, ! $record->is_accepting_orders, $admin)
                    )),
                EditAction::make(), DeleteAction::make(), RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [OpeningHoursRelationManager::class, HourExceptionsRelationManager::class, BranchProductsRelationManager::class];
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
