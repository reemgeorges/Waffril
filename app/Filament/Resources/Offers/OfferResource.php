<?php

namespace App\Filament\Resources\Offers;

use App\Filament\Resources\Offers\Pages\ManageOffers;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
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
use Filament\Support\Enums\FontWeight;
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

    protected static ?string $navParent = StoreResource::class;

    protected static string $labelKey = 'offer';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 40;

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

    /**
     * Public offers (table UX overhaul 2026-11-12): the store with what the
     * offer covers (branch, product or the whole store), the discount, the
     * period in one column, and its state now (running, scheduled, ended or
     * inactive) so an employee sees at a glance what customers get today.
     */
    public static function table(Table $table): Table
    {
        $state = fn (Offer $record) => match (true) {
            $record->trashed() || ! $record->is_active => 'inactive',
            $record->starts_at?->isFuture() => 'scheduled',
            $record->ends_at !== null && $record->ends_at->isPast() => 'ended',
            default => 'running',
        };
        $date = fn ($value) => $value?->translatedFormat(TableDefaults::dateTimeFormat());

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['store', 'branch', 'product']))
            ->columns([
                TextColumn::make('store.name')->label(__('admin.fields.store'))->weight(FontWeight::SemiBold)
                    ->description(fn (Offer $record) => collect([
                        $record->branch?->name ?? __('admin.offers.all_branches'),
                        $record->product?->name ?? __('admin.offers.all_products'),
                    ])->implode(' · ')),
                TextColumn::make('discount_value')->label(__('admin.fields.discount_value'))
                    ->formatStateUsing(fn ($state, Offer $record) => $record->discount_type === 'percent'
                        ? '−'.Fields::formatDecimal($state).'%' : '−'.Fields::formatDecimal($state))
                    ->badge()->icon('heroicon-m-tag')->color(fn (Offer $record) => $record->discount_type === 'percent' ? 'success' : 'info')
                    ->alignCenter()->width(TableDefaults::COMPACT_WIDTH),
                TextColumn::make('starts_at')->label(__('admin.table.offer_period'))->sortable()
                    ->formatStateUsing(fn ($state, Offer $record) => __('admin.table.period_from', ['date' => $date($record->starts_at)]))
                    ->description(fn (Offer $record) => $record->ends_at ? __('admin.table.period_to', ['date' => $date($record->ends_at)]) : __('admin.table.no_end'))
                    ->visibleFrom('md'),
                TextColumn::make('offer_state')->label(__('admin.table.offer_state'))->badge()->state($state)
                    ->formatStateUsing(fn (string $state) => __('admin.table.'.$state))
                    ->icon(fn (string $state) => match ($state) {
                        'running' => 'heroicon-m-bolt',
                        'scheduled' => 'heroicon-m-calendar',
                        'ended' => 'heroicon-m-check',
                        default => 'heroicon-m-pause-circle',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'running' => 'success',
                        'scheduled' => 'info',
                        default => 'gray',
                    })->width(TableDefaults::COMPACT_WIDTH),
            ])
            ->filters([
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->searchable()->preload(),
                SelectFilter::make('offer_state')->label(__('admin.table.offer_state'))
                    ->options(collect(['running', 'scheduled', 'ended', 'inactive'])->mapWithKeys(fn ($k) => [$k => __('admin.table.'.$k)])->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'running' => $query->where('is_active', true)->where('starts_at', '<=', now())
                            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now())),
                        'scheduled' => $query->where('is_active', true)->where('starts_at', '>', now()),
                        'ended' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', now()),
                        'inactive' => $query->where('is_active', false),
                        default => $query,
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())),
                RowActions::more([...RowActions::activation(static::class), DeleteAction::make(), RestoreAction::make()]),
            ])
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
