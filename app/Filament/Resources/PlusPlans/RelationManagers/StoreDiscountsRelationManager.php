<?php

namespace App\Filament\Resources\PlusPlans\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\PlusStoreDiscount;
use App\Models\Store;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Plan-level store discounts: percent or fixed, optional cap, minimum
 * order and per-cycle usage limit. Applies from the next confirmed cycle.
 */
class StoreDiscountsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'store_discount';

    protected static string $relationship = 'storeDiscounts';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.plus.store_discounts');
    }

    public static function discountFields(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('discount_type')->label(__('admin.fields.discount_type'))->required()->live()
                    ->options(['percent' => __('admin.discount.percent'), 'fixed' => __('admin.discount.fixed')]),
                TextInput::make('discount_value')->label(__('admin.fields.discount_value'))->required()->numeric()->gt(0)->rule('decimal:0,4')
                    ->maxValue(fn (Get $get) => $get('discount_type') === 'percent' ? 100 : null),
                TextInput::make('max_discount_amount')->label(__('admin.fields.max_discount_amount'))->numeric()->gt(0)->rule('decimal:0,4'),
                Fields::money('min_order_amount', required: false),
                TextInput::make('max_uses_per_cycle')->label(__('admin.fields.max_uses_per_cycle'))->integer()->minValue(1)->maxValue(1000),
            ]),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('store_id')->label(__('admin.fields.store'))->required()->searchable()
                ->options(fn () => Store::all()->mapWithKeys(fn ($s) => [$s->id => $s->name]))
                ->rule(fn (?PlusStoreDiscount $record) => Rule::unique('plus_store_discounts', 'store_id')
                    ->where('plus_plan_id', $this->getOwnerRecord()->id)->ignore($record?->id)),
            ...self::discountFields(),
            TextInput::make('note')->label(__('admin.fields.note'))->maxLength(255),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    /**
     * Plan → Store discounts (table UX overhaul 2026-11-12): the store, the
     * discount, its limits in words and status.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store'))->weight(FontWeight::SemiBold)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('store', fn ($q) => $q->where('name', 'like', "%{$search}%"))),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))->badge()->color('gold')->icon('heroicon-m-tag')
                    ->formatStateUsing(fn ($state, $record) => '−'.Fields::formatDecimal($state).($record->discount_type === 'percent' ? '%' : ''))
                    ->description(fn ($record) => collect([
                        $record->max_discount_amount !== null ? __('admin.table.max_discount', ['amount' => Fields::formatDecimal($record->max_discount_amount)]) : null,
                        $record->min_order_amount !== null ? __('admin.table.min_order_short', ['amount' => Fields::formatDecimal($record->min_order_amount)]) : null,
                        $record->max_uses_per_cycle ? trans_choice('admin.table.uses_per_cycle', (int) $record->max_uses_per_cycle, ['count' => (int) $record->max_uses_per_cycle]) : null,
                    ])->filter()->implode(' · ') ?: null),
                Columns::status(),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['is_excluded' => false])])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))),
                RowActions::more([DeleteAction::make()]),
            ]);
    }
}
