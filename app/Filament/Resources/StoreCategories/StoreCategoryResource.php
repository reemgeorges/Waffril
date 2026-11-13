<?php

namespace App\Filament\Resources\StoreCategories;

use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\RowActions;
use App\Filament\Support\SafeCategoryDelete;
use App\Models\StoreCategory;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * WAFFRLI (2026-10-31). Store categories (restaurants, supermarkets, ...).
 * Delete = soft delete (restorable); force delete is never offered.
 */
class StoreCategoryResource extends AdminResource
{
    protected static ?string $model = StoreCategory::class;

    /**
     * UX overhaul (2026-11-12): not in the sidebar. Managed from its owning
     * workflow (Stores → store → Branches / Products / categories / ratings);
     * the pages and routes stay, protected by the same policy.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string $labelKey = 'store_category';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formFields());
    }

    /**
     * Also used to create a category straight from the store form
     * (UX redesign 2026-11-11), so both places validate the same way.
     *
     * @return list<Component>
     */
    public static function formFields(): array
    {
        return [
            Fields::translatable('name', fillsSlug: true)->columnSpanFull(),
            Grid::make(2)->columnSpanFull()->schema([
                FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('store-categories')->maxSize(2048)
                    ->helperText(__('admin.help.category_image')),
                Grid::make(1)->schema([
                    Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)
                        ->helperText(__('admin.help.store_category_active')),
                    TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->minValue(0)->default(0)
                        ->helperText(__('admin.help.sort_order')),
                ]),
            ]),
            Fields::moreSettings([Fields::slug('store_categories')])->columnSpanFull(),
        ];
    }

    /**
     * Store categories (opened from Stores → Categories & ratings; table UX
     * overhaul 2026-11-12): few records with few fields, so each is a small
     * card — picture (or initial), name, a link to its stores, status. Edit
     * is the primary action; the safe delete sits under "More actions".
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        Columns::picture('image', fn (StoreCategory $record) => $record->name, 44)->grow(false),
                        Stack::make([
                            TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)->size(TextSize::Large),
                            TextColumn::make('sort_order')->label(__('admin.fields.sort_order'))->color('gray')->size(TextSize::Small)
                                ->formatStateUsing(fn ($state) => __('admin.table.sort_position', ['n' => $state])),
                        ])->space(1),
                        Columns::status()->grow(false),
                    ]),
                    // The store count opens the store list filtered on this category.
                    Columns::countLink('stores_count', __('admin.resources.store.plural'), 'admin.table.stores_count', 'heroicon-m-building-storefront',
                        fn ($record) => StoreResource::canViewAny() ? StoreResource::getUrl('index', ['filters' => ['categories' => ['values' => [$record->id]]]]) : null,
                        __('admin.table.open_stores'))->counts('stores'),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->searchPlaceholder(__('admin.table.search_categories'))
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.status'))
                    ->trueLabel(__('admin.table.active'))->falseLabel(__('admin.table.inactive')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::primary(EditAction::make()->label(__('admin.table.edit'))->mutateRecordDataUsing(Fields::withTranslations())->modalWidth(Width::TwoExtraLarge)),
                RowActions::more([...RowActions::activation(static::class), SafeCategoryDelete::make('store'), RestoreAction::make()]),
            ])
            ->emptyStateDescription(__('admin.empty.store_categories'))
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStoreCategories::route('/')];
    }
}
