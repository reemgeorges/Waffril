<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\City;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Service cities (GPS detection center + radius).
 * Cities are deactivated, never deleted.
 */
class CityResource extends AdminResource
{
    protected static ?string $model = City::class;

    protected static string $labelKey = 'city';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::translatable('name'),
            Grid::make(3)->schema([
                TextInput::make('center_lat')->label(__('admin.fields.center_lat'))->required()->numeric()->minValue(-90)->maxValue(90),
                TextInput::make('center_lng')->label(__('admin.fields.center_lng'))->required()->numeric()->minValue(-180)->maxValue(180),
                TextInput::make('radius_km')->label(__('admin.fields.radius_km'))->required()->numeric()->gt(0)->maxValue(9999.99),
            ]),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('center_lat')->label(__('admin.fields.center_lat')),
                TextColumn::make('center_lng')->label(__('admin.fields.center_lng')),
                TextColumn::make('radius_km')->label(__('admin.fields.radius_km'))->sortable(),
                TextColumn::make('branches_count')->counts('branches')->label(__('admin.resources.branch.plural')),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn (City $record) => ! static::can('update', $record)),
                TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->sortable(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('admin.fields.is_active'))])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCities::route('/')];
    }
}
