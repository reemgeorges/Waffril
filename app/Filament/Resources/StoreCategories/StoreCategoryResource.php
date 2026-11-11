<?php

namespace App\Filament\Resources\StoreCategories;

use App\Filament\Resources\StoreCategories\Pages\ManageStoreCategories;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\StoreCategory;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable()->tooltip(__('admin.fields.sort_order')),
                ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public')->imageSize(36)->square(),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold),
                TextColumn::make('slug')->label(__('admin.fields.slug'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                // The store count opens the store list filtered on this category.
                TextColumn::make('stores_count')->counts('stores')->label(__('admin.resources.store.plural'))
                    ->url(fn ($record) => StoreResource::canViewAny() ? StoreResource::getUrl('index', ['tableFilters' => ['categories' => ['value' => $record->id]]]) : null)
                    ->color('primary'),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(Fields::withTranslations())->modalWidth(Width::TwoExtraLarge),
                ActionGroup::make([DeleteAction::make(), RestoreAction::make()]),
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
