<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Filament\Resources\DeliveryAreas\DeliveryAreaResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\MapPicker;
use App\Filament\Support\RowActions;
use App\Models\City;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
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

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        // 2026-11-16: two full-width sections (they used to sit side by side in
        // the modal, squeezing the coordinates to ~105 px and wrapping their
        // labels). Coordinates and radius share one balanced row; the map
        // (click or drag; the fields stay editable and validated) sits
        // full width below them.
        return $schema->components([
            Section::make(__('admin.cities.section_identity'))->icon('heroicon-o-building-office-2')->columnSpanFull()->schema([
                Fields::translatable('name'),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0)
                        ->helperText(__('admin.cities.sort_order_help')),
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)->inline(false)
                        ->helperText(__('admin.cities.is_active_help')),
                ]),
            ]),
            Section::make(__('admin.cities.section_area'))->icon('heroicon-o-map')->columnSpanFull()
                ->description(__('admin.cities.section_area_help'))->schema([
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        TextInput::make('center_lat')->label(__('admin.fields.center_lat'))->required()->numeric()->minValue(-90)->maxValue(90)->live(debounce: 600)->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make('center_lng')->label(__('admin.fields.center_lng'))->required()->numeric()->minValue(-180)->maxValue(180)->live(debounce: 600)->extraInputAttributes(['dir' => 'ltr']),
                        // The unit is the suffix; what the radius means is in the section description.
                        TextInput::make('radius_km')->label(__('admin.fields.radius_km'))->required()->numeric()->gt(0)->maxValue(9999.99)->live(debounce: 600)
                            ->suffix(__('admin.cities.km'))->extraInputAttributes(['dir' => 'ltr']),
                    ]),
                    MapPicker::point('center_lat', 'center_lng', 'radius_km'),
                ]),
        ]);
    }

    /**
     * Cities (table UX overhaul 2026-11-12): a handful of records, so each
     * city is a card — name, coverage radius, its branches and delivery
     * areas (links), status. Map coordinates stay in the edit form.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)->size(TextSize::Large),
                            TextColumn::make('radius_km')->label(__('admin.fields.radius_km'))->color('gray')->size(TextSize::Small)->icon('heroicon-m-map')
                                ->formatStateUsing(fn ($state) => __('admin.table.radius', ['km' => Fields::formatDecimal($state)])),
                        ])->space(1),
                        Columns::status()->grow(false),
                    ]),
                    Split::make([
                        Columns::countLink('branches_count', __('admin.resources.branch.plural'), 'admin.table.branches_count', 'heroicon-m-building-office-2',
                            fn (City $record) => BranchResource::canViewAny() ? BranchResource::getUrl('index', ['filters' => ['city_id' => ['value' => $record->getKey()]]]) : null,
                            __('admin.table.open_city_branches'))->counts('branches')->grow(false),
                        Columns::countLink('delivery_areas_count', __('admin.resources.delivery_area.plural'), 'admin.table.areas_count', 'heroicon-m-map-pin',
                            fn (City $record) => DeliveryAreaResource::canViewAny() ? DeliveryAreaResource::getUrl('index', ['filters' => ['city_id' => ['value' => $record->getKey()]]]) : null)
                            ->counts('deliveryAreas')->grow(false),
                    ])->extraAttributes(['class' => 'wf-card-stats']),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->searchPlaceholder(__('admin.table.search_cities'))
            ->filters([TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive'))])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())),
                RowActions::more(RowActions::activation(static::class)),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCities::route('/')];
    }
}
