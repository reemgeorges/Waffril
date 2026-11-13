<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\BranchOptionPricesRelationManager;
use App\Filament\Resources\Products\RelationManagers\BranchPricesRelationManager;
use App\Filament\Resources\Products\RelationManagers\OptionGroupsRelationManager;
use App\Filament\Resources\Products\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\Products\RelationManagers\RatingsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\TableDefaults;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Store-owned products: one image, several
 * categories, option groups (sizes / choices / add-ons), per-branch prices
 * and availability, per-branch option prices, and rating moderation.
 * The owning store cannot change after creation.
 */
class ProductResource extends AdminResource
{
    protected static ?string $model = Product::class;

    /**
     * UX overhaul (2026-11-12): not in the sidebar. Managed from its owning
     * workflow (Stores → store → Branches / Products / categories / ratings);
     * the pages and routes stay, protected by the same policy.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $labelKey = 'product';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 30;

    /**
     * UX redesign (2026-11-11): the product (main column) beside its picture
     * and visibility (side column). Product categories are platform-wide (not
     * per store); a missing one can be created here by staff allowed to.
     * Opened from a store ("Add product"), the store is pre-selected.
     * Branch prices and options are managed in the tabs below the form.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make(__('admin.products.section_info'))->icon('heroicon-o-cube')->columnSpan(['default' => 1, 'lg' => 2])->schema([
                    Select::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->searchable()->preload()
                        ->default(fn () => request()->integer('store') ?: null)
                                // Inside Store → Branches/Products the store is the owner record.
                        ->hidden(fn ($livewire) => $livewire instanceof RelationManager)
                        ->helperText(__('admin.help.product_store'))
                        ->disabled(fn (?Product $record) => $record !== null)->dehydrated(fn (?Product $record) => $record === null),
                    Fields::translatable('name'),
                    Select::make('categories')->label(__('admin.resources.category.plural'))
                        ->relationship('categories', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                        ->multiple()->preload()->required()
                        ->helperText(__('admin.help.product_categories'))
                        ->when(CategoryResource::canCreate(), fn (Select $select) => $select
                            ->createOptionForm(CategoryResource::formFields())
                            ->createOptionModalHeading(__('admin.products.new_category'))),
                    Fields::translatable('description', required: false, multiline: true),
                ]),
                Section::make(__('admin.products.section_display'))->icon('heroicon-o-photo')->columnSpan(['default' => 1, 'lg' => 1])->schema([
                    FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('products')->maxSize(3072)
                        ->helperText(__('admin.help.product_image')),
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)
                        ->helperText(__('admin.help.product_active')),
                ]),
            ]),
        ]);
    }

    /**
     * All products (opened from Stores; table UX overhaul 2026-11-12):
     * picture, name with its store and categories, branch pricing, rating
     * and status. "Prices & availability" is the primary action.
     */
    public static function table(Table $table): Table
    {
        $edit = fn (Product $record, ?string $tab = null) => static::getUrl('edit', ['record' => $record]).($tab ? '?relation='.$tab : '');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['store', 'categories']))
            ->columns([
                Columns::picture('image', fn (Product $record) => $record->name),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->sortable()->weight(FontWeight::SemiBold)
                    ->description(fn (Product $record) => collect([$record->store?->name, $record->categories->map->name->implode(' · ')])->filter()->implode(' — ')),
                TextColumn::make('branch_products_count')->counts('branchProducts')->label(__('admin.stores.sold_in_branches'))->badge()
                    ->formatStateUsing(fn ($state) => (int) $state > 0
                        ? trans_choice('admin.table.branches_count', (int) $state, ['count' => (int) $state])
                        : __('admin.table.not_priced'))
                    ->icon(fn ($state) => (int) $state > 0 ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'warning')
                    ->url(fn (Product $record) => $edit($record, 'branch-prices'))
                    ->extraAttributes(['class' => 'wf-stat-link'])->width(TableDefaults::COMPACT_WIDTH),
                Columns::stars('rating', fn (Product $record) => [$record->averageRating(), (int) $record->rating_count])
                    ->url(fn (Product $record) => $edit($record, 'ratings'))->visibleFrom('md'),
                Columns::status(),
            ])
            ->searchPlaceholder(__('admin.table.search_product_name'))
            ->filters([
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->searchable()->preload(),
                SelectFilter::make('categories')->label(__('admin.resources.category.plural'))->relationship('categories', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->multiple()->preload(),
                TernaryFilter::make('priced')->label(__('admin.table.priced_filter'))
                    ->trueLabel(__('admin.table.priced'))->falseLabel(__('admin.table.not_priced'))
                    ->queries(
                        true: fn (Builder $query) => $query->has('branchProducts'),
                        false: fn (Builder $query) => $query->doesntHave('branchProducts'),
                    ),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(Action::make('manage')->label(__('admin.table.prices_and_availability'))->icon('heroicon-m-currency-dollar')
                    ->url(fn (Product $record) => $edit($record, 'branch-prices'))
                    ->visible(fn (Product $record) => static::can('update', $record))),
                RowActions::more([
                    EditAction::make()->label(__('admin.table.edit_details')),
                    Action::make('ratings')->label(__('admin.table.ratings'))->icon('heroicon-m-star')
                        ->url(fn (Product $record) => $edit($record, 'ratings')),
                    ...RowActions::activation(static::class),
                    DeleteAction::make(), RestoreAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        // Pricing first: after adding a product the admin sets its price in
        // each branch (?relation=branch-prices). Keys allow direct links.
        return [
            'branch-prices' => BranchPricesRelationManager::class,
            'option-groups' => OptionGroupsRelationManager::class,
            'options' => OptionsRelationManager::class,
            'branch-option-prices' => BranchOptionPricesRelationManager::class,
            'ratings' => RatingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
