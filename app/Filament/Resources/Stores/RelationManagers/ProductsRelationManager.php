<?php

namespace App\Filament\Resources\Stores\RelationManagers;

use App\Filament\Concerns\TranslatedRelationLabels;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Store → Products (UX overhaul 2026-11-12). Products belong to this store
 * (two stores selling "the same" item keep their own picture, text and
 * prices). Add a product in a dialog without leaving the store; open it for
 * branch prices, options and ratings. Uses the product resource's form,
 * policy and validation; the global product list stays available.
 */
class ProductsRelationManager extends RelationManager
{
    use TranslatedRelationLabels;

    protected static string $relationLabelKey = 'product';

    protected static string $relationship = 'products';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-cube';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.resources.product.plural');
    }

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->products()->count();
    }

    public function form(Schema $schema): Schema
    {
        return ProductResource::form($schema);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('categories')->withCount('branchProducts')
                ->withoutGlobalScopes([SoftDeletingScope::class]))
            ->columns([
                ImageColumn::make('image')->label(__('admin.fields.image'))->disk('public')->imageSize(40)->square(),
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (Product $record) => $record->categories->map->name->implode(' · ')),
                TextColumn::make('branch_products_count')->label(__('admin.stores.sold_in_branches'))
                    ->tooltip(__('admin.stores.sold_in_branches_help')),
                TextColumn::make('rating')->label(__('admin.fields.rating'))
                    ->state(fn (Product $record) => $record->rating_count > 0
                        ? '★ '.$record->averageRating().' ('.$record->rating_count.')'
                        : __('admin.products.no_genuine_ratings'))
                    ->color(fn (Product $record) => $record->rating_count > 0 ? 'warning' : 'gray'),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn ($record) => ! ProductResource::can('update', $record)),
            ])
            ->filters([
                SelectFilter::make('categories')->label(__('admin.resources.category.plural'))->relationship('categories', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.stores.add_product_short'))->modalWidth(Width::FiveExtraLarge)
                    ->visible(fn () => ProductResource::canCreate()),
            ])
            ->recordActions([
                Action::make('manage')->label(__('admin.stores.manage_product'))->icon('heroicon-m-adjustments-horizontal')
                    ->url(fn ($record) => ProductResource::getUrl('edit', ['record' => $record])),
            ])
            ->recordUrl(fn ($record) => ProductResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('admin.stores.no_products'))
            ->emptyStateDescription(__('admin.stores.no_products_help'))
            ->emptyStateIcon('heroicon-o-cube');
    }
}
