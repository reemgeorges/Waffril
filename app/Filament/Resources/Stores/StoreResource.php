<?php

namespace App\Filament\Resources\Stores;

use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StoreCategories\StoreCategoryResource;
use App\Filament\Resources\Stores\Pages\CreateStore;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\Stores\RelationManagers\ProductsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label(__('admin.fields.logo'))->disk('public')->circular()->imageSize(36),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (Store $record) => $record->categories->map->name->implode(' · ')),
                TextColumn::make('slug')->label(__('admin.fields.slug'))->toggleable(isToggledHiddenByDefault: true),
                // Counts open the branch / product lists filtered on this store.
                TextColumn::make('branches_count')->counts('branches')->label(__('admin.resources.branch.plural'))->color('primary')
                    ->url(fn (Store $record) => BranchResource::canViewAny() ? BranchResource::getUrl('index', ['tableFilters' => ['store_id' => ['value' => $record->id]]]) : null),
                TextColumn::make('products_count')->counts('products')->label(__('admin.resources.product.plural'))->color('primary')
                    ->url(fn (Store $record) => ProductResource::canViewAny() ? ProductResource::getUrl('index', ['tableFilters' => ['store_id' => ['value' => $record->id]]]) : null),
                TextColumn::make('plus_discount_percent')->label(__('admin.stores.plus_short'))->alignCenter()->width('1%')
                    ->formatStateUsing(fn ($state, $record) => $record->plusDiscountPercentText() !== null ? $record->plusDiscountPercentText().'%' : '—')->sortable(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categories'))
            ->filters([
                SelectFilter::make('categories')->label(__('admin.resources.store_category.plural'))
                    ->relationship('categories', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('newBranch')->label(__('admin.stores.add_branch'))->icon('heroicon-m-plus')
                        ->url(fn (Store $record) => BranchResource::getUrl('create', ['store' => $record->id]))
                        ->visible(fn () => BranchResource::canCreate()),
                    Action::make('newProduct')->label(__('admin.stores.add_product'))->icon('heroicon-m-plus')
                        ->url(fn (Store $record) => ProductResource::getUrl('create', ['store' => $record->id]))
                        ->visible(fn () => ProductResource::canCreate()),
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
