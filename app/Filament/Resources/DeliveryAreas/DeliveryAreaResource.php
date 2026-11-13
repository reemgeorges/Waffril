<?php

namespace App\Filament\Resources\DeliveryAreas;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\DeliveryAreas\Pages\ManageDeliveryAreas;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\DeliveryArea;
use App\Support\Geo\Polygon;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Platform-wide delivery areas. The boundary is a
 * GeoJSON Polygon ([lng, lat] pairs, one closed ring); its bounding box is
 * derived automatically on save.
 */
class DeliveryAreaResource extends AdminResource
{
    protected static ?string $model = DeliveryArea::class;

    protected static ?string $navParent = CityResource::class;

    protected static string $labelKey = 'delivery_area';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->searchable()->preload(),
            Fields::translatable('name'),
            Textarea::make('boundary')->label(__('admin.fields.boundary'))
                ->helperText(__('admin.help.boundary'))
                ->rows(8)->required()
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                ->dehydrateStateUsing(fn ($state) => is_string($state) ? json_decode($state, true) : $state)
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    $decoded = is_string($value) ? json_decode($value, true) : $value;
                    if ($error = Polygon::validationError($decoded)) {
                        $fail($error);
                    }
                }),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    /**
     * Delivery areas (table UX overhaul 2026-11-12): small cards — area name
     * and its city, status. The bounding box stays in the edit form.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('city'))
            ->columns([
                Stack::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)->size(TextSize::Large),
                            TextColumn::make('city.name')->label(__('admin.fields.city'))->color('gray')->size(TextSize::Small)->icon('heroicon-m-map-pin'),
                        ])->space(1),
                        Columns::status()->grow(false),
                    ]),

                ])->space(3),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->searchPlaceholder(__('admin.table.search_areas'))
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())),
                RowActions::more(RowActions::activation(static::class)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliveryAreas::route('/')];
    }
}
