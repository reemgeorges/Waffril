<?php

namespace App\Filament\Resources\PlusPlans\RelationManagers;

use App\Filament\Support\Fields;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Plan-level store discounts: percent or fixed, optional cap, minimum
 * order and per-cycle usage limit. Applies from the next confirmed cycle.
 */
class StoreDiscountsRelationManager extends RelationManager
{
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store')),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))
                    ->formatStateUsing(fn ($state, $record) => $record->discount_type === 'percent' ? $state.'%' : $state),
                TextColumn::make('max_discount_amount')->label(__('admin.fields.max_discount_amount'))->placeholder('—'),
                TextColumn::make('min_order_amount')->label(__('admin.fields.min_order_amount'))->placeholder('—'),
                TextColumn::make('max_uses_per_cycle')->label(__('admin.fields.max_uses_per_cycle'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['is_excluded' => false])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
