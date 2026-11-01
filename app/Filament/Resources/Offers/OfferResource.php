<?php

namespace App\Filament\Resources\Offers;

use App\Filament\Resources\Offers\Pages\ManageOffers;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\Offer;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Public store offers (store-wide, one branch, or
 * one product). PLUS store discounts always take priority and never
 * combine with offers; there are no coupons.
 */
class OfferResource extends AdminResource
{
    protected static ?string $model = Offer::class;

    protected static string $labelKey = 'offer';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->live()->searchable()->preload(),
            Grid::make(2)->schema([
                Select::make('branch_id')->label(__('admin.fields.branch'))->placeholder(__('admin.offers.all_branches'))
                    ->options(fn (Get $get) => Branch::where('store_id', $get('store_id'))->get()->mapWithKeys(fn ($b) => [$b->id => $b->name]))
                    ->rule(fn (Get $get) => fn ($a, $v, $fail) => $v && ! Branch::where('id', $v)->where('store_id', $get('store_id'))->exists() ? $fail(__('admin.errors.not_in_store')) : null),
                Select::make('product_id')->label(__('admin.fields.product'))->placeholder(__('admin.offers.all_products'))
                    ->options(fn (Get $get) => Product::where('store_id', $get('store_id'))->get()->mapWithKeys(fn ($p) => [$p->id => $p->name]))
                    ->rule(fn (Get $get) => fn ($a, $v, $fail) => $v && ! Product::where('id', $v)->where('store_id', $get('store_id'))->exists() ? $fail(__('admin.errors.not_in_store')) : null),
            ]),
            Fields::translatable('name', required: false),
            Grid::make(2)->schema([
                Select::make('discount_type')->label(__('admin.fields.discount_type'))->required()->live()
                    ->options(['percent' => __('admin.discount.percent'), 'fixed' => __('admin.discount.fixed')]),
                TextInput::make('discount_value')->label(__('admin.fields.discount_value'))->required()->numeric()->gt(0)->rule('decimal:0,4')
                    ->maxValue(fn (Get $get) => $get('discount_type') === 'percent' ? 100 : null),
                DateTimePicker::make('starts_at')->label(__('admin.fields.starts_at'))->required()->seconds(false)->default(now()),
                DateTimePicker::make('ends_at')->label(__('admin.fields.ends_at'))->seconds(false)->after('starts_at'),
            ]),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store')),
                TextColumn::make('branch.name')->label(__('admin.fields.branch'))->placeholder(__('admin.offers.all_branches')),
                TextColumn::make('product.name')->label(__('admin.fields.product'))->placeholder(__('admin.offers.all_products')),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))
                    ->formatStateUsing(fn ($state, Offer $record) => $record->discount_type === 'percent' ? $state.'%' : $state),
                TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime()->sortable(),
                TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime()->placeholder('—'),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(Fields::withTranslations()), DeleteAction::make(), RestoreAction::make()])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOffers::route('/')];
    }
}
