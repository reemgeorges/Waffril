<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\BranchOptionPricesRelationManager;
use App\Filament\Resources\Products\RelationManagers\BranchPricesRelationManager;
use App\Filament\Resources\Products\RelationManagers\OptionGroupsRelationManager;
use App\Filament\Resources\Products\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\Products\RelationManagers\RatingsRelationManager;
use App\Filament\Support\AdminResource;
use App\Filament\Support\Fields;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
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

    protected static string $labelKey = 'product';

    protected static string|\UnitEnum|null $navigationGroup = 'catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->searchable()->preload()
                    ->disabled(fn (?Product $record) => $record !== null)->dehydrated(fn (?Product $record) => $record === null),
                Fields::translatable('name'),
                Fields::translatable('description', required: false, multiline: true),
                Select::make('categories')->label(__('admin.resources.category.plural'))
                    ->relationship('categories', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                    ->multiple()->preload()->required(),
                FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('products')->maxSize(3072),
                Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public'),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable(),
                TextColumn::make('store.name')->label(__('admin.fields.store')),
                TextColumn::make('categories.name')->label(__('admin.resources.category.plural'))->badge(),
                TextColumn::make('rating')->label(__('admin.fields.rating'))
                    ->state(fn (Product $record) => $record->rating_count > 0
                        ? $record->averageRating().' ('.$record->rating_count.')'
                        : __('admin.products.no_genuine_ratings')),
                TextColumn::make('branch_products_count')->counts('branchProducts')->label(__('admin.resources.branch.plural')),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! static::can('update', $record)),
            ])
            ->filters([
                SelectFilter::make('store_id')->label(__('admin.fields.store'))->relationship('store', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                SelectFilter::make('categories')->label(__('admin.resources.category.plural'))->relationship('categories', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            OptionGroupsRelationManager::class,
            OptionsRelationManager::class,
            BranchPricesRelationManager::class,
            BranchOptionPricesRelationManager::class,
            RatingsRelationManager::class,
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
