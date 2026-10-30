<?php

namespace App\Filament\Resources\DeliveryAreas;

use App\Filament\Resources\DeliveryAreas\Pages\ManageDeliveryAreas;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\DeliveryArea;
use App\Support\Geo\Polygon;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Platform-wide delivery areas. The boundary is a
 * GeoJSON Polygon ([lng, lat] pairs, one closed ring); its bounding box is
 * derived automatically on save.
 */
class DeliveryAreaResource extends AdminResource
{
    protected static ?string $model = DeliveryArea::class;

    protected static string $labelKey = 'delivery_area';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 40;

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('city.name')->label(__('admin.fields.city')),
                TextColumn::make('min_lat')->label(__('admin.fields.bounding_box'))
                    ->formatStateUsing(fn (DeliveryArea $record) => "{$record->min_lat}, {$record->min_lng} → {$record->max_lat}, {$record->max_lng}"),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn (DeliveryArea $record) => ! static::can('update', $record)),
            ])
            ->filters([SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliveryAreas::route('/')];
    }
}
