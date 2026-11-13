<?php

namespace App\Filament\Resources\Stores;

use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\ProductRatings\ProductRatingResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\Stores\RelationManagers\ProductsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Avatar;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Stores (one logo each; no merchant login). A
 * store owns its products; branches share them with their own prices.
 */
class StoreResource extends AdminResource
{
    protected static ?string $model = Store::class;

    protected static string $labelKey = 'store';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 10;

    /**
     * UX redesign (2026-11-11): what the store is (main column) beside how it
     * shows in the app (side column). A missing store category can be created
     * right here, with the same fields and rules as the category page, by
     * staff allowed to create categories.
     */
    /**
     * "Stores" stays highlighted on the catalog pages that live under it and
     * are not in the sidebar themselves (branches, products, categories,
     * ratings), so staff always see where they are.
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [
            static::getRouteBaseName().'.*',
            BranchResource::getRouteBaseName().'.*',
            ProductResource::getRouteBaseName().'.*',
            StoreCategoryResource::getRouteBaseName().'.*',
            CategoryResource::getRouteBaseName().'.*',
            ProductRatingResource::getRouteBaseName().'.*',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Group::make()->columnSpan(['default' => 1, 'lg' => 2])->schema([
                    Section::make(__('admin.stores.section_info'))->icon('heroicon-o-building-storefront')->schema([
                        Fields::translatable('name', fillsSlug: true),
                        Select::make('categories')->label(__('admin.resources.store_category.plural'))
                            ->relationship('categories', 'slug')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                            ->multiple()->preload()->required()
                            ->helperText(__('admin.help.store_categories'))
                            ->when(StoreCategoryResource::canCreate(), fn (Select $select) => $select
                                ->createOptionForm(StoreCategoryResource::formFields())
                                ->createOptionModalHeading(__('admin.stores.new_category'))),
                        Fields::translatable('description', required: false, multiline: true),
                    ]),
                    Section::make(__('admin.stores.section_contact'))->icon('heroicon-o-phone')
                        ->description(__('admin.stores.section_contact_help'))
                        ->columns(2)->schema([Fields::phone('contact_phone'), Fields::phone('contact_whatsapp')]),
                ]),
                Group::make()->columnSpan(['default' => 1, 'lg' => 1])->schema([
                    Section::make(__('admin.stores.section_visibility'))->icon('heroicon-o-eye')->schema([
                        Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)
                            ->helperText(__('admin.help.store_active')),
                        TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0)
                            ->helperText(__('admin.help.sort_order')),
                    ]),
                    Section::make(__('admin.fields.logo'))->icon('heroicon-o-photo')->schema([
                        FileUpload::make('logo')->hiddenLabel()->image()->imageEditor(false)->disk('public')->directory('stores')->maxSize(2048)
                            ->helperText(__('admin.help.logo')),
                    ]),
                    // WAFFRLI PLUS (2026-11-12): discount on the merchandise subtotal for
                    // ACTIVE members only (never delivery). A plan rule or an approved
                    // member exception for this store takes precedence.
                    Section::make(__('admin.stores.section_plus'))->icon('heroicon-o-star')->schema([
                        TextInput::make('plus_discount_percent')->label(__('admin.fields.plus_discount_percent'))
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)->rule('decimal:0,2')->default(0)->suffix('%')->required()
                            ->helperText(__('admin.help.plus_discount_percent')),
                    ]),
                ]),
            ]),
            Fields::moreSettings([Fields::slug('stores')])->columnSpanFull(),
        ]);
    }

    /**
     * Stores list — the reference layout of the table UX overhaul
     * (2026-11-12). A store list has few fields, so each store is a compact
     * card instead of a sparse full-width row: logo (or an initial
     * placeholder), name and categories, a clear status, then two links that
     * open this store's Branches / Products tab. "Manage store" is the one
     * primary action; the rest sits under "More actions". 1 card per row on
     * phones, 2 on tablets, 3 on wide screens.
     */
    public static function table(Table $table): Table
    {
        $storeTab = fn (string $tab) => fn (Store $record) => static::can('update', $record)
            ? static::getUrl('edit', ['record' => $record]).'?relation='.$tab
            : null;

        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        ImageColumn::make('logo')->label(__('admin.fields.logo'))->disk('public')
                            ->defaultImageUrl(fn (Store $record) => Avatar::placeholder($record->name))
                            ->imageSize(48)->square()->grow(false)->extraImgAttributes(['class' => 'wf-avatar', 'alt' => '']),
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))
                                ->searchable()
                                ->weight(FontWeight::SemiBold)->size(TextSize::Large)
                                ->url(fn (Store $record) => static::can('update', $record) ? static::getUrl('edit', ['record' => $record]) : null)
                                ->extraAttributes(['class' => 'wf-card-title']),
                            TextColumn::make('category_names')->label(__('admin.resources.store_category.plural'))
                                ->state(fn (Store $record) => $record->categories->map->name->implode(' · ') ?: null)
                                ->color('gray')->size(TextSize::Small)->placeholder(null),
                        ])->space(1),
                        TextColumn::make('status')->label(__('admin.fields.status'))->grow(false)->badge()
                            ->state(fn (Store $record) => match (true) {
                                $record->trashed() => 'deleted',
                                $record->is_active => 'active',
                                default => 'inactive',
                            })
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'deleted' => __('admin.table.deleted'),
                                'active' => __('admin.table.active'),
                                default => __('admin.table.inactive'),
                            })
                            ->icon(fn (string $state) => match ($state) {
                                'active' => 'heroicon-m-check-circle',
                                'deleted' => 'heroicon-m-trash',
                                default => 'heroicon-m-pause-circle',
                            })
                            ->color(fn (string $state) => match ($state) {
                                'active' => 'success',
                                'deleted' => 'danger',
                                default => 'gray',
                            }),
                    ])->from('sm'),
                    Split::make([
                        // Counts open this store's own Branches / Products tab.
                        TextColumn::make('branches_count')->counts('branches')->label(__('admin.resources.branch.plural'))
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.branches_count', (int) $state, ['count' => (int) $state]))
                            ->icon('heroicon-m-building-office-2')->badge()->color('primary')->grow(false)
                            ->url($storeTab('branches'))->tooltip(__('admin.table.open_branches'))
                            ->extraAttributes(['class' => 'wf-stat-link']),
                        TextColumn::make('products_count')->counts('products')->label(__('admin.resources.product.plural'))
                            ->formatStateUsing(fn ($state) => trans_choice('admin.table.products_count', (int) $state, ['count' => (int) $state]))
                            ->icon('heroicon-m-cube')->badge()->color('primary')->grow(false)
                            ->url($storeTab('products'))->tooltip(__('admin.table.open_products'))
                            ->extraAttributes(['class' => 'wf-stat-link']),
                        TextColumn::make('plus_discount_percent')->label(__('admin.stores.plus_short'))->grow(false)
                            ->state(fn (Store $record) => $record->plusDiscountPercentText() !== null ? 'PLUS −'.$record->plusDiscountPercentText().'%' : null)
                            ->badge()->color('gold')->icon('heroicon-m-star')->placeholder(null),
                    ])->extraAttributes(['class' => 'wf-card-stats']),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, '2xl' => 3])
            ->recordUrl(null)
            ->defaultSort('name')
            ->searchPlaceholder(__('admin.table.search_stores'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categories'))
            ->filters([
                SelectFilter::make('categories')->label(__('admin.resources.store_category.plural'))
                    ->relationship('categories', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                    ->multiple()->preload(),
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.manage_store'))->icon('heroicon-m-cog-6-tooth')),
                RowActions::more([
                    Action::make('branches')->label(__('admin.stores.branches_tab'))->icon('heroicon-m-building-office-2')
                        ->url($storeTab('branches'))
                        ->visible(fn (Store $record) => static::can('update', $record)),
                    Action::make('products')->label(__('admin.stores.products_tab'))->icon('heroicon-m-cube')
                        ->url($storeTab('products'))
                        ->visible(fn (Store $record) => static::can('update', $record)),
                    ...RowActions::activation(static::class),
                    DeleteAction::make(), RestoreAction::make(),
                ]),
            ])
            ->emptyStateDescription(__('admin.empty.stores'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        // Keyed so links can open a tab directly (?relation=branches|products).
        return ['branches' => BranchesRelationManager::class, 'products' => ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
