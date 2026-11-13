<?php

namespace App\Filament\Resources\DeliveryFeeRules;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\DeliveryFeeRules\Pages\ManageDeliveryFeeRules;
use App\Filament\Support\AdminResource;
use App\Filament\Support\BusinessAction;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\DeliveryArea;
use App\Models\DeliveryFeeRule;
use App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * WAFFRLI (2026-10-31). Platform-wide delivery fee bands (area → city →
 * global; the most specific scope with active rules is authoritative).
 * All writes go through DeliveryFeeRuleService::persist() so overlapping
 * active bands are refused.
 */
class DeliveryFeeRuleResource extends AdminResource
{
    protected static ?string $model = DeliveryFeeRule::class;

    protected static ?string $navParent = CityResource::class;

    protected static string $labelKey = 'delivery_fee_rule';

    protected static string|\UnitEnum|null $navigationGroup = 'platform';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                    ->placeholder(__('admin.delivery.global_scope'))->live()->searchable()->preload(),
                Select::make('delivery_area_id')->label(__('admin.fields.delivery_area'))
                    ->options(fn (Get $get) => $get('city_id')
                        ? DeliveryArea::where('city_id', $get('city_id'))->get()->mapWithKeys(fn ($a) => [$a->id => $a->name])
                        : [])
                    ->placeholder(__('admin.delivery.whole_city')),
            ]),
            Grid::make(2)->schema([
                TextInput::make('distance_from_km')->label(__('admin.fields.distance_from_km'))->required()->numeric()->minValue(0)->default(0),
                TextInput::make('distance_to_km')->label(__('admin.fields.distance_to_km'))->required()->numeric()->gt('distance_from_km'),
                Fields::money('base_fee'),
                Fields::money('per_km_fee'),
            ]),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Table UX overhaul (2026-11-12): where the rule applies (scope with
            // its city / area), the distance band, the fee as one formula, status.
            ->modifyQueryUsing(fn ($query) => $query->with(['city', 'deliveryArea']))
            ->columns([
                TextColumn::make('scope')->label(__('admin.fields.scope'))->badge()->color('gray')
                    ->state(fn (DeliveryFeeRule $record) => __('admin.delivery.scope_'.$record->scopeType()))
                    ->description(fn (DeliveryFeeRule $record) => collect([$record->city?->name, $record->deliveryArea?->name])->filter()->implode(' › ') ?: null),
                // One readable distance band instead of two raw columns.
                TextColumn::make('distance_from_km')->label(__('admin.delivery.distance_band'))->sortable()
                    ->state(fn ($record) => Fields::formatDecimal($record->distance_from_km).' – '
                        .($record->distance_to_km !== null ? Fields::formatDecimal($record->distance_to_km) : '∞').' '.__('admin.delivery.km'))
                    ->extraAttributes(['class' => 'wf-num']),
                TextColumn::make('base_fee')->label(__('admin.table.fee'))->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, DeliveryFeeRule $record) => (float) $record->per_km_fee > 0
                        ? __('admin.table.fee_formula', ['base' => Fields::formatDecimal($record->base_fee), 'per_km' => Fields::formatDecimal($record->per_km_fee)])
                        : Fields::formatDecimal($record->base_fee)),
                Columns::status(),
            ])
            ->filters([
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->using(fn (DeliveryFeeRule $record, array $data) => BusinessAction::run(
                    fn ($admin) => app(DeliveryFeeRuleService::class)->persist($data, $record, $admin->id)
                ) ?? $record)),
                RowActions::more([Action::make('toggle')->label(fn (DeliveryFeeRule $record) => $record->is_active ? __('admin.actions.deactivate') : __('admin.actions.activate'))
                    ->icon('heroicon-o-power')->requiresConfirmation()
                    ->visible(fn (DeliveryFeeRule $record) => static::can('update', $record))
                    ->action(fn (DeliveryFeeRule $record) => BusinessAction::run(
                        fn ($admin) => app(DeliveryFeeRuleService::class)->persist(['is_active' => ! $record->is_active], $record, $admin->id)
                    ))]),
            ])
            ->defaultSort('distance_from_km');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliveryFeeRules::route('/')];
    }
}
